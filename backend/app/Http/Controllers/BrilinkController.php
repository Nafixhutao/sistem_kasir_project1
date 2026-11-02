<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TransaksiTransfer;
use App\Models\TransaksiTarikTunai;
use App\Models\TransaksiSetorTunai;
use App\Models\PembayaranTagihan;
use App\Models\TransaksiPulsa;
use App\Models\TransaksiEwallet;

class BrilinkController extends Controller
{
    public function riwayat(Request $request)
    {
        $filters = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'jenis' => 'nullable|in:semua,transfer,tarik_tunai,setor_tunai,tagihan,pulsa,ewallet',
            'search' => 'nullable|string|max:100',
        ]);

        $startDate = $filters['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $filters['end_date'] ?? now()->toDateString();
        $jenis = $filters['jenis'] ?? 'semua';

        $data = [];

        if ($jenis === 'semua' || $jenis === 'transfer') {
            $transfers = TransaksiTransfer::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => 'Transfer']));
            $data = array_merge($data, $transfers->toArray());
        }

        if ($jenis === 'semua' || $jenis === 'tarik_tunai') {
            $tarik = TransaksiTarikTunai::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => 'Tarik Tunai']));
            $data = array_merge($data, $tarik->toArray());
        }

        if ($jenis === 'semua' || $jenis === 'setor_tunai') {
            $setor = TransaksiSetorTunai::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => 'Setor Tunai']));
            $data = array_merge($data, $setor->toArray());
        }

        if ($jenis === 'semua' || $jenis === 'tagihan') {
            $tagihan = PembayaranTagihan::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => 'Tagihan']));
            $data = array_merge($data, $tagihan->toArray());
        }

        if ($jenis === 'semua' || $jenis === 'pulsa') {
            $pulsa = TransaksiPulsa::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => 'Pulsa']));
            $data = array_merge($data, $pulsa->toArray());
        }

        if ($jenis === 'semua' || $jenis === 'ewallet') {
            $ewallet = TransaksiEwallet::whereBetween('tanggal', [$startDate, $endDate])->get()
                ->map(fn($t) => array_merge($t->toArray(), ['jenis' => $t->jenis_transaksi === 'top_up' ? 'Top Up E-Wallet' : 'Pencairan E-Wallet']));
            $data = array_merge($data, $ewallet->toArray());
        }

        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $data = collect($data)->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['kode_transaksi'] ?? ''), $search)
                    || str_contains(strtolower($item['nama_penerima'] ?? $item['nama_pemilik_rekening'] ?? $item['nama_pelanggan'] ?? ''), $search)
                    || str_contains(strtolower($item['penyedia'] ?? ''), $search)
                    || str_contains(strtolower($item['bank_tujuan'] ?? $item['operator'] ?? $item['jenis_ewallet'] ?? ''), $search)
                    || str_contains(strtolower($item['nomor_rekening_tujuan'] ?? $item['nomor_rekening'] ?? $item['nomor_ewallet'] ?? $item['nomor_tujuan'] ?? $item['nomor_pelanggan'] ?? $item['nomor_hp'] ?? ''), $search);
            })->values()->toArray();
        }

        usort($data, fn($a, $b) => strtotime($b['dibuat_pada']) - strtotime($a['dibuat_pada']));

        return response()->json([
            'data' => $data,
            'total_admin' => collect($data)->sum('biaya_admin'),
            'total_transaksi' => count($data),
        ]);
    }
}
