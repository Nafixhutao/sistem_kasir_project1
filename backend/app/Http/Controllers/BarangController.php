<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\ActivityLog;
use App\Models\DetailPenjualan;
use App\Services\NotifikasiService;
use Illuminate\Http\Request;

class BarangController extends Controller
{
    public function index()
    {
        return response()->json(Barang::with('supplier:id,nama_supplier')->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode_barang' => 'required|string|max:50|unique:barang,kode_barang',
            'nama_barang' => 'required|string|max:150',
            'kategori' => 'required|string|max:100',
            'stok' => 'required|integer|min:0',
            'harga_beli' => 'required|numeric|min:0',
            'harga_jual' => 'required|numeric|min:0',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        $barang = Barang::create($data);
        NotifikasiService::syncStockNotification($barang, null);
        ActivityLog::record('Barang', 'create', 'Menambah barang ' . $barang->nama_barang, $barang->toArray());
        return response()->json($barang->load('supplier:id,nama_supplier'), 201);
    }

    public function show($id)
    {
        return response()->json(Barang::with('supplier:id,nama_supplier')->findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $barang = Barang::findOrFail($id);
        $data = $request->validate([
            'kode_barang' => 'required|string|max:50|unique:barang,kode_barang,' . $id,
            'nama_barang' => 'required|string|max:150',
            'kategori' => 'required|string|max:100',
            'stok' => 'required|integer|min:0',
            'harga_beli' => 'required|numeric|min:0',
            'harga_jual' => 'required|numeric|min:0',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);
        $previousStock = (int) $barang->stok;
        $barang->update($data);
        NotifikasiService::syncStockNotification($barang, $previousStock);
        ActivityLog::record('Barang', 'update', 'Memperbarui barang ' . $barang->nama_barang, $barang->toArray());
        return response()->json($barang->load('supplier:id,nama_supplier'));
    }

    public function destroy($id)
    {
        $barang = Barang::findOrFail($id);
        if (DetailPenjualan::where('barang_id', $barang->id)->exists()) {
            return response()->json([
                'message' => 'Barang sudah pernah dipakai transaksi, jadi tidak bisa dihapus agar laporan tetap aman.',
            ], 422);
        }

        $nama = $barang->nama_barang;
        $barang->delete();
        ActivityLog::record('Barang', 'delete', 'Menghapus barang ' . $nama);
        return response()->json(['message' => 'Barang berhasil dihapus']);
    }

    public function search(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:150']);
        $query = Barang::with('supplier:id,nama_supplier');
        if (!empty($data['q'])) {
            $search = $data['q'];
            $query->where('kode_barang', 'like', "%{$search}%")
                  ->orWhere('nama_barang', 'like', "%{$search}%");
        }
        return response()->json($query->get());
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.kode_barang' => 'required|string|max:50',
            'items.*.nama_barang' => 'required|string|max:150',
            'items.*.kategori' => 'required|string|max:100',
            'items.*.stok' => 'required|integer|min:0',
            'items.*.harga_beli' => 'required|numeric|min:0',
            'items.*.harga_jual' => 'required|numeric|min:0',
            'items.*.supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        $created = 0;
        $updated = 0;

        foreach ($data['items'] as $item) {
            $barang = Barang::where('kode_barang', $item['kode_barang'])->first();
            if ($barang) {
                $previousStock = (int) $barang->stok;
                $barang->update($item);
                NotifikasiService::syncStockNotification($barang, $previousStock);
                $updated++;
            } else {
                $barang = Barang::create($item);
                NotifikasiService::syncStockNotification($barang, null);
                $created++;
            }
        }

        ActivityLog::record('Barang', 'import', "Import barang: {$created} baru, {$updated} diperbarui");

        return response()->json([
            'message' => 'Import barang berhasil',
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}
