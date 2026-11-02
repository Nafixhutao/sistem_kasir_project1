<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiPenjualan;
use App\Models\PembayaranQris;
use App\Models\ActivityLog;
use App\Models\ReturPelanggan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LaporanController extends Controller
{
    public function penjualan(Request $request)
    {
        $filters = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $startDate = $filters['start_date'] ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $filters['end_date'] ?? Carbon::now()->toDateString();

        $transaksi = TransaksiPenjualan::with('details')
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->orderBy('tanggal', 'desc')
            ->get();

        $transaksiLunas = $transaksi->where('status', 'lunas');
        $totalPenjualan = $transaksiLunas->sum('total_harga');
        $totalTransaksi = $transaksiLunas->count();

        $chartData = $transaksiLunas->groupBy(fn($t) => $t->tanggal->format('Y-m-d'))
            ->map(fn($group, $date) => [
                'tanggal' => $date,
                'total' => $group->sum('total_harga'),
                'jumlah' => $group->count(),
            ])->values();

        return response()->json([
            'transaksi' => $transaksi,
            'total_penjualan' => $totalPenjualan,
            'total_transaksi' => $totalTransaksi,
            'chart_data' => $chartData,
        ]);
    }

    public function returPelanggan(Request $request)
    {
        $filters = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'search' => 'nullable|string',
            'alasan' => 'nullable|string',
            'metode' => 'nullable|in:semua,pengembalian_dana,penggantian_barang,tunai,qris',
        ]);

        $startDate = $filters['start_date'] ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $filters['end_date'] ?? Carbon::now()->toDateString();

        $query = ReturPelanggan::query()
            ->whereBetween('tanggal_retur', [$startDate, $endDate]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('nomor_retur', 'like', "%{$search}%")
                    ->orWhere('kode_transaksi', 'like', "%{$search}%")
                    ->orWhere('kode_barang', 'like', "%{$search}%")
                    ->orWhere('nama_barang', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['alasan']) && $filters['alasan'] !== 'semua') {
            $query->where('alasan_retur', $filters['alasan']);
        }

        if (!empty($filters['metode']) && $filters['metode'] !== 'semua') {
            $query->where('metode_pengembalian_dana', $filters['metode']);
        }

        $retur = $query
            ->orderBy('tanggal_retur', 'desc')
            ->orderBy('dibuat_pada', 'desc')
            ->get();

        return response()->json([
            'retur' => $retur,
            'total_retur' => $retur->count(),
            'total_barang_retur' => $retur->sum('jumlah_retur'),
            'filter_options' => [
                'alasan' => ReturPelanggan::query()
                    ->select('alasan_retur')
                    ->distinct()
                    ->orderBy('alasan_retur')
                    ->pluck('alasan_retur')
                    ->values(),
            ],
        ]);
    }

    public function resetPenjualan(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $deleted = DB::transaction(function () use ($startDate, $endDate) {
            $ids = TransaksiPenjualan::whereBetween('tanggal', [$startDate, $endDate])->pluck('id');
            $count = $ids->count();

            if ($count === 0) {
                return 0;
            }

            PembayaranQris::whereIn('transaksi_penjualan_id', $ids)->delete();
            TransaksiPenjualan::whereIn('id', $ids)->delete();

            return $count;
        });

        ActivityLog::record('Penjualan', 'reset-report', "Reset laporan penjualan {$startDate} sampai {$endDate}: {$deleted} transaksi");

        return response()->json([
            'message' => 'Laporan penjualan berhasil direset',
            'deleted' => $deleted,
        ]);
    }

}
