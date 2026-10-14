<?php

namespace App\Http\Controllers;

use App\Models\TransaksiTransfer;
use App\Models\ActivityLog;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransferController extends Controller
{
    public function index()
    {
        return response()->json(TransaksiTransfer::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_transfer' => 'required|string|max:50',
            'bank_tujuan' => 'required|string|max:100',
            'nomor_rekening_tujuan' => 'required|string|max:30',
            'nama_penerima' => 'required|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_transfer' => 'required|numeric|min:1',
        ], [
            'provider.required' => 'Provider transaksi wajib diisi.',
            'jenis_transfer.required' => 'Jenis transfer wajib dipilih.',
            'bank_tujuan.required' => 'Bank atau tujuan transfer wajib diisi.',
            'nomor_rekening_tujuan.required' => 'Nomor rekening tujuan wajib diisi.',
            'nama_penerima.required' => 'Nama penerima wajib diisi.',
            'jenis_nasabah.required' => 'Jenis nasabah wajib dipilih.',
            'jenis_nasabah.in' => 'Jenis nasabah tidak valid.',
            'nominal_transfer.required' => 'Nominal transfer wajib diisi.',
            'nominal_transfer.numeric' => 'Nominal transfer harus berupa angka.',
            'nominal_transfer.min' => 'Nominal transfer minimal Rp 1.',
        ]);

        $nominalTransfer = (float) $data['nominal_transfer'];

        $data['kode_transaksi'] = 'TRF-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        $data['tanggal'] = Carbon::today();
        $data = BrilinkFeeService::withNasabahFee($data, 'transfer', 'nominal_transfer');
        $adminFee = BrilinkFeeService::adminFeeFrom($data);
        $data['kasir'] = $request->user()->username;

        $transaksi = TransaksiTransfer::create($data);
        ActivityLog::record('Transfer', 'create', 'Admin menambahkan transaksi BRILink Transfer sebesar Rp' . number_format($nominalTransfer, 0, ',', '.') . ' dengan biaya admin Rp' . number_format($adminFee, 0, ',', '.'), $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(TransaksiTransfer::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $t = TransaksiTransfer::findOrFail($id);
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_transfer' => 'required|string|max:50',
            'bank_tujuan' => 'required|string|max:100',
            'nomor_rekening_tujuan' => 'required|string|max:30',
            'nama_penerima' => 'required|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_transfer' => 'required|numeric|min:1',
            'keterangan' => 'nullable|string',
            'status' => 'nullable|in:sukses,gagal,pending',
        ]);

        $data = BrilinkFeeService::withNasabahFee($data, 'transfer', 'nominal_transfer');

        $t->update($data);
        return response()->json($t);
    }

    public function destroy($id)
    {
        TransaksiTransfer::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaksi transfer berhasil dihapus']);
    }
}
