<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReturSupplierRequest;
use App\Http\Requests\UpdateReturSupplierRequest;
use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\ReturSupplier;
use App\Services\NotifikasiService;
use App\Services\BusinessRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturSupplierController extends Controller
{
    private const FINAL_STATUSES = ['diterima', 'ditolak'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:semua,diproses,diterima,ditolak'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $supplier = $filters['supplier'] ?? null;
        $status = $filters['status'] ?? null;
        $search = strtolower($filters['search'] ?? '');

        $data = ReturSupplier::with('barang')
            ->when($startDate && $endDate, fn($query) => $query->whereBetween('tanggal_retur', [$startDate, $endDate]))
            ->when($supplier, fn($query) => $query->where('nama_supplier', 'like', "%{$supplier}%"))
            ->when($status && $status !== 'semua', fn($query) => $query->where('status_retur', $status))
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereRaw('LOWER(nomor_retur) like ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nama_supplier) like ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(kode_barang) like ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nama_barang) like ?', ["%{$search}%"]);
                });
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($data);
    }

    public function store(StoreReturSupplierRequest $request)
    {
        $payload = $request->validated();
        unset($payload['request_id']);
        $payload['barang_id']=(int)$payload['barang_id'];
        $payload['jumlah_retur']=(int)$payload['jumlah_retur'];
        $payload['tanggal_retur']=Carbon::parse($payload['tanggal_retur'])->toDateString();
        $payload['keterangan']=$payload['keterangan'] ?? null;
        [$retur] = BusinessRequest::execute(ReturSupplier::class, $request->validated('request_id'), $request->user()->id, $payload, function () use ($request) {
            $data = $request->validated();
            $barang = Barang::findOrFail($data['barang_id']);

            $retur = ReturSupplier::create([
                'nomor_retur' => $this->generateNomorRetur($data['tanggal_retur']),
                'tanggal_retur' => $data['tanggal_retur'],
                'nama_supplier' => $data['nama_supplier'],
                'barang_id' => $barang->id,
                'kode_barang' => $barang->kode_barang,
                'nama_barang' => $barang->nama_barang,
                'jumlah_retur' => (int) $data['jumlah_retur'],
                'alasan_retur' => $data['alasan_retur'],
                'status_retur' => 'diproses',
                'keterangan' => $data['keterangan'] ?? null,
                'kasir' => $request->user()->username,
            ]);

            ActivityLog::record('Retur Supplier', 'create', 'Admin membuat retur supplier ' . $barang->nama_barang . ' sebanyak ' . $retur->jumlah_retur . ' unit.', $retur->toArray());

            return $retur->load('barang');
        });
        $retur->load('barang');

        return response()->json($retur, 201);
    }

    public function show($id)
    {
        return response()->json(ReturSupplier::with('barang')->findOrFail($id));
    }

    public function update(UpdateReturSupplierRequest $request, $id)
    {
        $retur = DB::transaction(function () use ($request, $id) {
            $retur = ReturSupplier::lockForUpdate()->findOrFail($id);
            $data = $request->validated();
            $barang = Barang::lockForUpdate()->findOrFail($data['barang_id']);
            $previousStatus = $retur->status_retur;

            if ($this->isFinalStatus($previousStatus)) {
                abort(422, 'Status retur sudah final dan tidak dapat diubah.');
            }

            if ($retur->stok_dikurangi) {
                $oldBarang = Barang::lockForUpdate()->find($retur->barang_id);
                if ($oldBarang) {
                    $oldBarang->stok += (int) $retur->jumlah_retur;
                    $oldBarang->save();
                }
                $retur->stok_dikurangi = false;
            }

            $retur->fill([
                'tanggal_retur' => $data['tanggal_retur'],
                'nama_supplier' => $data['nama_supplier'],
                'barang_id' => $barang->id,
                'kode_barang' => $barang->kode_barang,
                'nama_barang' => $barang->nama_barang,
                'jumlah_retur' => (int) $data['jumlah_retur'],
                'alasan_retur' => $data['alasan_retur'],
                'status_retur' => $data['status_retur'],
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            $this->syncStockForStatus($retur, $barang);
            $retur->save();

            if ($previousStatus !== $retur->status_retur) {
                ActivityLog::record('Retur Supplier', 'update', 'Admin mengubah status menjadi ' . ucfirst($retur->status_retur) . '.', $retur->toArray());
                NotifikasiService::returSupplierStatusChanged($retur, $previousStatus);
            }

            return $retur->fresh('barang');
        });

        return response()->json($retur);
    }

    public function destroy($id)
    {
        $retur = DB::transaction(function () use ($id) {
            $retur = ReturSupplier::lockForUpdate()->findOrFail($id);
            if ($retur->stok_dikurangi) {
                $barang = Barang::lockForUpdate()->find($retur->barang_id);
                if ($barang) {
                    $barang->stok += (int) $retur->jumlah_retur;
                    $barang->save();
                }
            }
            $snapshot = $retur->toArray();
            $retur->delete();
            ActivityLog::record('Retur Supplier', 'delete', 'Admin menghapus retur supplier ' . $snapshot['nomor_retur'] . '.', $snapshot);

            return $snapshot;
        });

        return response()->json(['message' => 'Retur supplier berhasil dihapus', 'retur' => $retur]);
    }

    private function generateNomorRetur(string $date): string
    {
        $tanggal = Carbon::parse($date)->format('Ymd');
        $count = ReturSupplier::whereDate('tanggal_retur', Carbon::parse($date)->toDateString())->count() + 1;

        return 'RET-' . $tanggal . '-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function syncStockForStatus(ReturSupplier $retur, Barang $barang): void
    {
        if ($retur->status_retur === 'diterima' && !$retur->stok_dikurangi) {
            if ($barang->stok < (int) $retur->jumlah_retur) {
                abort(422, 'Stok ' . $barang->nama_barang . ' tidak cukup untuk retur. Sisa stok: ' . $barang->stok . ' pcs.');
            }
            $previousStock = (int) $barang->stok;
            $barang->stok -= (int) $retur->jumlah_retur;
            $barang->save();
            NotifikasiService::syncStockNotification($barang, $previousStock);
            $retur->stok_dikurangi = true;
        }

        if ($retur->status_retur !== 'diterima' && $retur->stok_dikurangi) {
            $previousStock = (int) $barang->stok;
            $barang->stok += (int) $retur->jumlah_retur;
            $barang->save();
            NotifikasiService::syncStockNotification($barang, $previousStock);
            $retur->stok_dikurangi = false;
        }
    }

    private function isFinalStatus(string $status): bool
    {
        return in_array($status, self::FINAL_STATUSES, true);
    }
}
