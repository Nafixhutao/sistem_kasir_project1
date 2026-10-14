<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TransaksiEwallet;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EwalletController extends Controller
{
    public function index()
    {
        return response()->json(TransaksiEwallet::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'jenis_transaksi' => 'required|in:top_up,pencairan',
            'provider' => 'required|string|max:100',
            'jenis_ewallet' => 'required|string|max:100',
            'nomor_ewallet' => 'required|string|max:30',
            'nama_customer' => 'nullable|string|max:100',
            'nominal' => 'required|numeric|min:1',
            'keterangan' => 'nullable|string',
        ], [
            'jenis_transaksi.required' => 'Jenis transaksi e-wallet wajib dipilih.',
            'jenis_transaksi.in' => 'Jenis transaksi e-wallet tidak valid.',
            'provider.required' => 'Provider transaksi wajib diisi.',
            'jenis_ewallet.required' => 'Jenis e-wallet wajib diisi.',
            'nomor_ewallet.required' => 'Nomor e-wallet wajib diisi.',
            'nominal.required' => 'Nominal e-wallet wajib diisi.',
            'nominal.numeric' => 'Nominal e-wallet harus berupa angka.',
            'nominal.min' => 'Nominal e-wallet minimal Rp 1.',
        ]);

        $data['kode_transaksi'] = 'EWL-' . date('Ymd') . '-' . strtoupper(Str::random(5));
        $data['tanggal'] = now()->toDateString();
        $data = BrilinkFeeService::withNominalFee($data, 'ewallet', 'nominal');
        $data['status'] = 'sukses';
        $data['kasir'] = $request->user()->username;

        $transaksi = TransaksiEwallet::create($data);
        ActivityLog::record('E-Wallet', 'create', 'Mencatat transaksi e-wallet ' . $transaksi->kode_transaksi, $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(TransaksiEwallet::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $transaksi = TransaksiEwallet::findOrFail($id);
        $data = $request->validate([
            'jenis_transaksi' => 'required|in:top_up,pencairan',
            'provider' => 'required|string|max:100',
            'jenis_ewallet' => 'required|string|max:100',
            'nomor_ewallet' => 'required|string|max:30',
            'nama_customer' => 'nullable|string|max:100',
            'nominal' => 'required|numeric|min:1',
            'keterangan' => 'nullable|string',
        ], [
            'jenis_transaksi.required' => 'Jenis transaksi e-wallet wajib dipilih.',
            'jenis_transaksi.in' => 'Jenis transaksi e-wallet tidak valid.',
            'provider.required' => 'Provider transaksi wajib diisi.',
            'jenis_ewallet.required' => 'Jenis e-wallet wajib diisi.',
            'nomor_ewallet.required' => 'Nomor e-wallet wajib diisi.',
            'nominal.required' => 'Nominal e-wallet wajib diisi.',
            'nominal.numeric' => 'Nominal e-wallet harus berupa angka.',
            'nominal.min' => 'Nominal e-wallet minimal Rp 1.',
        ]);

        $data = BrilinkFeeService::withNominalFee($data, 'ewallet', 'nominal');
        $transaksi->update($data);
        ActivityLog::record('E-Wallet', 'update', 'Memperbarui transaksi e-wallet ' . $transaksi->kode_transaksi, $transaksi->toArray());

        return response()->json($transaksi);
    }

    public function destroy($id)
    {
        $transaksi = TransaksiEwallet::findOrFail($id);
        $kode = $transaksi->kode_transaksi;
        $transaksi->delete();
        ActivityLog::record('E-Wallet', 'delete', 'Menghapus transaksi e-wallet ' . $kode);

        return response()->json(['message' => 'Transaksi e-wallet berhasil dihapus']);
    }
}
