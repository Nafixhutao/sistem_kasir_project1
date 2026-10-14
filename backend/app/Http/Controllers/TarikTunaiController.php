<?php

namespace App\Http\Controllers;

use App\Models\TransaksiTarikTunai;
use App\Models\ActivityLog;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TarikTunaiController extends Controller
{
    public function index()
    {
        return response()->json(TransaksiTarikTunai::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'nomor_rekening' => 'required|string|max:30',
            'nama_penerima' => 'required|string|max:100',
            'nomor_hp' => 'required|string|max:20',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_tarik' => 'required|numeric|min:1',
        ], [
            'provider.required' => 'Provider transaksi wajib diisi.',
            'nomor_rekening.required' => 'Nomor rekening wajib diisi.',
            'nama_penerima.required' => 'Nama penerima wajib diisi.',
            'nomor_hp.required' => 'Nomor HP wajib diisi.',
            'jenis_nasabah.required' => 'Jenis nasabah wajib dipilih.',
            'jenis_nasabah.in' => 'Jenis nasabah tidak valid.',
            'nominal_tarik.required' => 'Nominal tarik tunai wajib diisi.',
            'nominal_tarik.numeric' => 'Nominal tarik tunai harus berupa angka.',
            'nominal_tarik.min' => 'Nominal tarik tunai minimal Rp 1.',
        ]);

        $nominalTarik = (float) $data['nominal_tarik'];

        $data['kode_transaksi'] = 'TT-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        $data['tanggal'] = Carbon::today();
        $data = BrilinkFeeService::withNasabahFee($data, 'tarik_tunai', 'nominal_tarik');
        $adminFee = BrilinkFeeService::adminFeeFrom($data);
        $data['kasir'] = $request->user()->username;

        $transaksi = TransaksiTarikTunai::create($data);
        ActivityLog::record('Tarik Tunai', 'create', 'Admin menambahkan transaksi BRILink Tarik Tunai sebesar Rp' . number_format($nominalTarik, 0, ',', '.') . ' dengan biaya admin Rp' . number_format($adminFee, 0, ',', '.'), $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(TransaksiTarikTunai::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $t = TransaksiTarikTunai::findOrFail($id);
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'nomor_rekening' => 'required|string|max:30',
            'nama_penerima' => 'required|string|max:100',
            'nomor_hp' => 'required|string|max:20',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_tarik' => 'required|numeric|min:1',
            'status' => 'nullable|in:sukses,gagal',
        ]);

        $data = BrilinkFeeService::withNasabahFee($data, 'tarik_tunai', 'nominal_tarik');

        $t->update($data);
        return response()->json($t);
    }

    public function destroy($id)
    {
        TransaksiTarikTunai::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaksi tarik tunai berhasil dihapus']);
    }
}
