<?php

namespace App\Http\Controllers;

use App\Models\TransaksiSetorTunai;
use App\Models\ActivityLog;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SetorTunaiController extends Controller
{
    public function index()
    {
        return response()->json(TransaksiSetorTunai::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_setoran' => 'required|in:biasa,tabungan',
            'nomor_rekening_tujuan' => 'nullable|string|max:30',
            'nama_pemilik_rekening' => 'nullable|string|max:100',
            'bank_tujuan' => 'nullable|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_setor' => 'required|numeric|min:1',
            'sumber_dana' => 'nullable|string|max:100',
            'keterangan' => 'nullable|string',
        ], [
            'provider.required' => 'Provider transaksi wajib diisi.',
            'jenis_setoran.required' => 'Jenis setoran wajib dipilih.',
            'jenis_setoran.in' => 'Jenis setoran tidak valid.',
            'jenis_nasabah.required' => 'Jenis nasabah wajib dipilih.',
            'jenis_nasabah.in' => 'Jenis nasabah tidak valid.',
            'nominal_setor.required' => 'Nominal setor tunai wajib diisi.',
            'nominal_setor.numeric' => 'Nominal setor tunai harus berupa angka.',
            'nominal_setor.min' => 'Nominal setor tunai minimal Rp 1.',
        ]);

        $nominalSetor = (float) $data['nominal_setor'];

        $data['kode_transaksi'] = 'ST-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        $data['tanggal'] = Carbon::today();
        $data = BrilinkFeeService::withNasabahFee($data, 'setor_tunai', 'nominal_setor');
        $adminFee = BrilinkFeeService::adminFeeFrom($data);
        $data['kasir'] = $request->user()->username;

        $transaksi = TransaksiSetorTunai::create($data);
        ActivityLog::record('Setor Tunai', 'create', 'Admin menambahkan transaksi BRILink Setor Tunai sebesar Rp' . number_format($nominalSetor, 0, ',', '.') . ' dengan biaya admin Rp' . number_format($adminFee, 0, ',', '.'), $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(TransaksiSetorTunai::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $s = TransaksiSetorTunai::findOrFail($id);
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_setoran' => 'required|in:biasa,tabungan',
            'nomor_rekening_tujuan' => 'nullable|string|max:30',
            'nama_pemilik_rekening' => 'nullable|string|max:100',
            'bank_tujuan' => 'nullable|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'nominal_setor' => 'required|numeric|min:1',
            'sumber_dana' => 'nullable|string|max:100',
            'keterangan' => 'nullable|string',
            'status' => 'nullable|in:sukses,gagal',
        ]);

        $data = BrilinkFeeService::withNasabahFee($data, 'setor_tunai', 'nominal_setor');

        $s->update($data);
        return response()->json($s);
    }

    public function destroy($id)
    {
        TransaksiSetorTunai::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaksi setor tunai berhasil dihapus']);
    }
}
