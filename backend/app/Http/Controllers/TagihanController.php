<?php

namespace App\Http\Controllers;

use App\Models\PembayaranTagihan;
use App\Models\ActivityLog;
use App\Services\BrilinkFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TagihanController extends Controller
{
    public function index()
    {
        return response()->json(PembayaranTagihan::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_layanan' => 'required|in:pln,pdam,bpjs,indihome,angsuran,lainnya',
            'nomor_pelanggan' => 'required|string|max:50',
            'nama_pelanggan' => 'required|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'jumlah_tagihan' => 'required|numeric|min:1',
        ], [
            'provider.required' => 'Provider transaksi wajib diisi.',
            'jenis_layanan.required' => 'Jenis tagihan wajib dipilih.',
            'jenis_layanan.in' => 'Jenis tagihan tidak valid.',
            'nomor_pelanggan.required' => 'Nomor pelanggan wajib diisi.',
            'nama_pelanggan.required' => 'Nama pelanggan wajib diisi.',
            'jenis_nasabah.required' => 'Jenis nasabah wajib dipilih.',
            'jenis_nasabah.in' => 'Jenis nasabah tidak valid.',
            'jumlah_tagihan.required' => 'Jumlah tagihan wajib diisi.',
            'jumlah_tagihan.numeric' => 'Jumlah tagihan harus berupa angka.',
            'jumlah_tagihan.min' => 'Jumlah tagihan minimal Rp 1.',
        ]);

        $jumlahTagihan = (float) $data['jumlah_tagihan'];

        $data['kode_transaksi'] = 'TAG-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        $data['tanggal'] = Carbon::today();
        $data = BrilinkFeeService::withNasabahFee($data, 'tagihan', 'jumlah_tagihan');
        $adminFee = BrilinkFeeService::adminFeeFrom($data);
        $data['kasir'] = $request->user()->username;

        $transaksi = PembayaranTagihan::create($data);
        ActivityLog::record('Pembayaran Tagihan', 'create', 'Admin menambahkan transaksi BRILink Pembayaran Tagihan sebesar Rp' . number_format($jumlahTagihan, 0, ',', '.') . ' dengan biaya admin Rp' . number_format($adminFee, 0, ',', '.'), $transaksi->toArray());

        return response()->json($transaksi, 201);
    }

    public function show($id)
    {
        return response()->json(PembayaranTagihan::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $t = PembayaranTagihan::findOrFail($id);
        $data = $request->validate([
            'provider' => 'required|string|max:100',
            'jenis_layanan' => 'required|in:pln,pdam,bpjs,indihome,angsuran,lainnya',
            'nomor_pelanggan' => 'required|string|max:50',
            'nama_pelanggan' => 'required|string|max:100',
            'jenis_nasabah' => 'required|in:internal,eksternal',
            'jumlah_tagihan' => 'required|numeric|min:1',
            'status' => 'nullable|in:sukses,gagal',
        ]);

        $data = BrilinkFeeService::withNasabahFee($data, 'tagihan', 'jumlah_tagihan');

        $t->update($data);
        return response()->json($t);
    }

    public function destroy($id)
    {
        PembayaranTagihan::findOrFail($id)->delete();
        return response()->json(['message' => 'Data tagihan berhasil dihapus']);
    }
}
