<?php

namespace App\Http\Controllers;

use App\Models\TransaksiPulsa;
use App\Models\ActivityLog;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PulsaController extends Controller
{
    public function index()
    {
        return response()->json(TransaksiPulsa::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'operator' => 'required|string|max:50',
            'jenis_layanan' => 'required|in:pulsa,paket_data',
            'nomor_tujuan' => 'required|string|max:20',
            'produk' => 'required|string|max:150',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'harga' => 'required|numeric|min:1',
        ], [
            'provider.required' => 'Provider transaksi wajib diisi.',
            'operator.required' => 'Operator wajib dipilih.',
            'jenis_layanan.required' => 'Jenis layanan wajib dipilih.',
            'jenis_layanan.in' => 'Jenis layanan tidak valid.',
            'nomor_tujuan.required' => 'Nomor tujuan wajib diisi.',
            'produk.required' => 'Nama produk pulsa/paket data wajib diisi.',
            'jenis_nasabah.required' => 'Jenis nasabah wajib dipilih.',
            'jenis_nasabah.in' => 'Jenis nasabah tidak valid.',
            'harga.required' => 'Harga wajib diisi.',
            'harga.numeric' => 'Harga harus berupa angka.',
            'harga.min' => 'Harga minimal Rp 1.',
        ]);

        $harga = (float) $data['harga'];

        $data['kode_transaksi'] = 'PLS-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        $data['tanggal'] = Carbon::today();
        $data = BrilinkFeeService::withNasabahFee($data, 'pulsa_paket_data', 'harga');
        $adminFee = BrilinkFeeService::adminFeeFrom($data);
        $data['kasir'] = $request->user()->username;

        $transaksi = TransaksiPulsa::create($data);
        ActivityLog::record('Pulsa', 'create', 'Admin menambahkan transaksi BRILink Pulsa/Paket Data sebesar Rp' . number_format($harga, 0, ',', '.') . ' dengan biaya admin Rp' . number_format($adminFee, 0, ',', '.'), $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(TransaksiPulsa::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $p = TransaksiPulsa::findOrFail($id);
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'operator' => 'required|string|max:50',
            'jenis_layanan' => 'required|in:pulsa,paket_data',
            'nomor_tujuan' => 'required|string|max:20',
            'produk' => 'required|string|max:150',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'harga' => 'required|numeric|min:1',
            'status' => 'nullable|in:sukses,gagal',
        ]);

        $data = BrilinkFeeService::withNasabahFee($data, 'pulsa_paket_data', 'harga');

        $p->update($data);
        return response()->json($p);
    }

    public function destroy($id)
    {
        TransaksiPulsa::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaksi pulsa berhasil dihapus']);
    }
}
