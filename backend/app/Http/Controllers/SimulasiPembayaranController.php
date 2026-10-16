<?php

namespace App\Http\Controllers;

use App\Models\PembayaranQris;
use App\Models\ActivityLog;
use App\Services\NotifikasiService;
use Illuminate\Http\Request;

class SimulasiPembayaranController extends Controller
{
    /**
     * Halaman "Simulasi Pembayaran" untuk demo mode sandbox.
     *
     * Dipakai selama QRIS belum bisa dibayar sungguhan (sandbox SumoPod tidak punya
     * bank/acquirer yang mengonfirmasi). Pelanggan scan QR dari layar kasir, membuka
     * halaman ini, lalu menekan "Bayar Sekarang" — status di aplikasi kasir berubah
     * lunas dengan sendirinya lewat pembaruan status berkala.
     */
    public function show(string $orderId)
    {
        $qris = $this->temukan($orderId);

        return view('simulasi-bayar', [
            'qris' => $qris,
            'sudahLunas' => $qris && $qris->status_pembayaran === 'settlement',
            'kedaluwarsa' => $qris && in_array($qris->status_pembayaran, ['expire', 'cancel', 'deny'], true),
        ]);
    }

    /**
     * Tandai pembayaran lunas dari halaman simulasi.
     */
    public function bayar(Request $request, string $orderId)
    {
        $qris = $this->temukan($orderId);

        if (!$qris) {
            abort(404, 'Pembayaran tidak ditemukan.');
        }

        if ($qris->status_pembayaran !== 'settlement') {
            $previousStatus = $qris->status_pembayaran;
            $qris->status_pembayaran = 'settlement';
            $qris->paid_at = now();
            $qris->payment_response = array_merge((array) $qris->payment_response, [
                'simulasi_publik' => [
                    'at' => now()->toISOString(),
                    'environment' => 'sandbox',
                ],
            ]);
            $qris->save();

            NotifikasiService::qrisStatusChanged($qris->fresh(), $previousStatus, 'settlement');
            ActivityLog::record('Simulasi', 'bayar', 'Pembayaran simulasi untuk order ' . $qris->order_id, [
                'order_id' => $qris->order_id,
                'nominal' => $qris->nominal,
            ]);
        }

        return redirect()->route('simulasi.bayar', ['orderId' => $orderId]);
    }

    /**
     * Halaman ini hanya hidup selama mode demo sandbox aktif; kalau flag dimatikan
     * (mis. sebelum produksi) halaman dianggap tidak ada supaya QR tidak pernah
     * mengarah ke simulasi saat pembayaran sungguhan sudah dipakai.
     */
    private function temukan(string $orderId): ?PembayaranQris
    {
        abort_unless($this->aktif(), 404);

        return PembayaranQris::where('order_id', $orderId)->first();
    }

    private function aktif(): bool
    {
        return config('services.sumopod.allow_manual_confirm', false)
            && str_contains((string) config('services.sumopod.base_url'), 'sandbox');
    }
}
