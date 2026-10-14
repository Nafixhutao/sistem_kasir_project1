<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PengeluaranToko;
use Illuminate\Http\Request;

class PengeluaranTokoController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'kategori' => 'nullable|string|max:100',
        ]);

        $query = PengeluaranToko::query()->orderBy('tanggal', 'desc')->orderBy('created_at', 'desc');
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('tanggal', [$filters['start_date'], $filters['end_date']]);
        }
        if (!empty($filters['kategori']) && $filters['kategori'] !== 'semua') {
            $query->where('kategori', $filters['kategori']);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tanggal' => 'required|date',
            'kategori' => 'required|string|max:100',
            'nama_pengeluaran' => 'required|string|max:150',
            'nominal' => 'required|numeric|min:1',
            'keterangan' => 'nullable|string',
        ]);

        $pengeluaran = PengeluaranToko::create(array_merge($data, ['kasir' => $request->user()->username]));
        ActivityLog::record('Pengeluaran', 'create', 'Mencatat pengeluaran ' . $pengeluaran->nama_pengeluaran, $pengeluaran->toArray());

        return response()->json($pengeluaran, 201);
    }

    public function destroy($id)
    {
        $pengeluaran = PengeluaranToko::findOrFail($id);
        $nama = $pengeluaran->nama_pengeluaran;
        $pengeluaran->delete();
        ActivityLog::record('Pengeluaran', 'delete', 'Menghapus pengeluaran ' . $nama);

        return response()->json(['message' => 'Pengeluaran berhasil dihapus']);
    }
}
