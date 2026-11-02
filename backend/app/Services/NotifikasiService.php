<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\Notifikasi;
use App\Models\PembayaranQris;
use App\Models\ReturPelanggan;
use App\Models\ReturSupplier;
use App\Models\User;

class NotifikasiService
{
    private const LOW_STOCK_THRESHOLD = 5;

    public static function notifyAll(
        string $tipe,
        string $judul,
        string $pesan,
        ?string $referensiTipe,
        ?int $referensiId,
        ?string $url,
        string $fingerprint
    ): void {
        User::query()->select('id')->orderBy('id')->chunkById(100, function ($users) use ($tipe, $judul, $pesan, $referensiTipe, $referensiId, $url, $fingerprint) {
            foreach ($users as $user) {
                self::notifyUser((int) $user->id, $tipe, $judul, $pesan, $referensiTipe, $referensiId, $url, $fingerprint);
            }
        });
    }

    public static function notifyUser(
        int $userId,
        string $tipe,
        string $judul,
        string $pesan,
        ?string $referensiTipe,
        ?int $referensiId,
        ?string $url,
        string $fingerprint
    ): void {
        Notifikasi::firstOrCreate(
            [
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
            ],
            [
                'tipe' => $tipe,
                'judul' => $judul,
                'pesan' => $pesan,
                'referensi_tipe' => $referensiTipe,
                'referensi_id' => $referensiId,
                'url' => $url,
            ]
        );
    }

    public static function syncStockNotification(Barang $barang, ?int $previousStock): void
    {
        $currentState = self::stockState((int) $barang->stok);
        $previousState = $previousStock === null ? null : self::stockState($previousStock);

        if ($currentState === 'normal') {
            self::markStockNotificationsRead($barang);
            return;
        }

        if ($currentState === $previousState) {
            return;
        }

        $updatedAt = now()->format('YmdHisv');
        $fingerprint = "stok:{$currentState}:{$barang->id}:{$updatedAt}";

        if ($currentState === 'habis') {
            self::notifyAll(
                'stok_habis',
                'Stok barang habis',
                "Stok {$barang->nama_barang} ({$barang->kode_barang}) sudah habis.",
                'barang',
                (int) $barang->id,
                '/barang',
                $fingerprint
            );
            return;
        }

        self::notifyAll(
            'stok_menipis',
            'Stok barang menipis',
            "Stok {$barang->nama_barang} ({$barang->kode_barang}) tersisa {$barang->stok} pcs.",
            'barang',
            (int) $barang->id,
            '/barang',
            $fingerprint
        );
    }

    public static function qrisStatusChanged(PembayaranQris $qris, string $previousStatus, string $newStatus): void
    {
        if ($previousStatus === $newStatus) {
            return;
        }

        if ($newStatus === 'settlement') {
            self::notifyAll(
                'qris_berhasil',
                'Pembayaran QRIS berhasil',
                "Pembayaran QRIS order {$qris->order_id} sebesar Rp" . number_format((float) $qris->nominal, 0, ',', '.') . ' berhasil.',
                'pembayaran_qris',
                (int) $qris->id,
                '/transaksi-penjualan',
                "qris:settlement:{$qris->id}:{$qris->transaction_id}:{$newStatus}"
            );
            return;
        }

        if (in_array($newStatus, ['expire', 'cancel'], true)) {
            self::notifyAll(
                'qris_gagal',
                'Pembayaran QRIS gagal',
                "Pembayaran QRIS order {$qris->order_id} berstatus {$newStatus}.",
                'pembayaran_qris',
                (int) $qris->id,
                '/transaksi-penjualan',
                "qris:gagal:{$qris->id}:{$newStatus}:{$qris->diperbarui_pada?->format('YmdHis')}"
            );
        }
    }

    public static function returPelangganProcessed(ReturPelanggan $retur): void
    {
        self::notifyAll(
            'retur_pelanggan_diproses',
            'Retur pelanggan diproses',
            "Retur pelanggan {$retur->nomor_retur} untuk {$retur->nama_barang} sebanyak {$retur->jumlah_retur} pcs berhasil dicatat.",
            'retur_pelanggan',
            (int) $retur->id,
            '/retur-barang-pelanggan',
            "retur-pelanggan:create:{$retur->id}"
        );
    }

    public static function returSupplierStatusChanged(ReturSupplier $retur, string $previousStatus): void
    {
        if ($previousStatus === $retur->status_retur) {
            return;
        }

        self::notifyAll(
            'retur_supplier_status',
            'Status retur supplier berubah',
            "Retur supplier {$retur->nomor_retur} berubah dari {$previousStatus} menjadi {$retur->status_retur}.",
            'retur_supplier',
            (int) $retur->id,
            '/retur-supplier',
            "retur-supplier:status:{$retur->id}:{$retur->status_retur}"
        );
    }

    private static function stockState(int $stock): string
    {
        if ($stock <= 0) {
            return 'habis';
        }

        return $stock <= self::LOW_STOCK_THRESHOLD ? 'menipis' : 'normal';
    }

    private static function markStockNotificationsRead(Barang $barang): void
    {
        Notifikasi::query()
            ->where('referensi_tipe', 'barang')
            ->where('referensi_id', $barang->id)
            ->whereIn('tipe', ['stok_menipis', 'stok_habis'])
            ->whereNull('dibaca_at')
            ->update(['dibaca_at' => now(), 'diperbarui_pada' => now()]);
    }
}
