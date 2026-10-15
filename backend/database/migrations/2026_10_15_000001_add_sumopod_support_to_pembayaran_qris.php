<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dukungan SumoPod Managed Payment.
 *
 * Alur SumoPod berbeda dari Midtrans: permintaan pembayaran dibuat lebih dulu,
 * lalu pelanggan membayar, dan konfirmasi datang lewat webhook. Karena itu baris
 * pembayaran_qris harus boleh dibuat sebelum transaksi penjualan ada, sehingga
 * transaksi_penjualan_id dibuat nullable dan ditautkan saat kasir menyimpan nota.
 */
return new class extends Migration {
    private const EXPECTED_TRANSAKSI_TYPE = 'int unsigned';
    private const ADDED_COLUMNS = ['provider', 'payment_url', 'paid_at'];

    private function columns(): array
    {
        $columns = [];
        foreach (DB::select('SHOW COLUMNS FROM `pembayaran_qris`') as $column) {
            $columns[$column->Field] = $column;
        }

        return $columns;
    }

    public function up(): void
    {
        if (!Schema::hasTable('pembayaran_qris')) {
            return;
        }

        // Jalur cepat untuk migrate --pretend: koneksi tidak mengeksekusi DDL,
        // jadi cukup pastikan struktur belum berubah.
        if (DB::connection()->pretending()) {
            return;
        }

        $columns = $this->columns();

        foreach (self::ADDED_COLUMNS as $added) {
            if (isset($columns[$added])) {
                throw new RuntimeException("Kolom {$added} sudah ada; tidak ada DDL dijalankan.");
            }
        }

        $transaksi = $columns['transaksi_penjualan_id'] ?? null;
        if ($transaksi === null
            || $transaksi->Type !== self::EXPECTED_TRANSAKSI_TYPE
            || $transaksi->Null !== 'NO') {
            throw new RuntimeException('Struktur transaksi_penjualan_id tidak sesuai dugaan; tidak ada DDL dijalankan.');
        }

        // Satu ALTER agar tidak meninggalkan struktur setengah jadi bila gagal.
        DB::statement(
            'ALTER TABLE `pembayaran_qris` '
            . "ADD COLUMN `provider` VARCHAR(20) NOT NULL DEFAULT 'midtrans' AFTER `order_id`, "
            . 'ADD COLUMN `payment_url` VARCHAR(500) NULL AFTER `snap_token`, '
            . 'ADD COLUMN `paid_at` TIMESTAMP NULL AFTER `payment_response`, '
            . 'MODIFY `transaksi_penjualan_id` INT UNSIGNED NULL'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('pembayaran_qris') || DB::connection()->pretending()) {
            return;
        }

        $columns = $this->columns();
        if (!isset($columns['provider'])) {
            return;
        }

        if (DB::table('pembayaran_qris')->whereNull('transaksi_penjualan_id')->exists()) {
            throw new RuntimeException('Masih ada pembayaran QRIS tanpa transaksi; rollback dibatalkan.');
        }

        DB::statement('ALTER TABLE `pembayaran_qris` MODIFY `transaksi_penjualan_id` INT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE `pembayaran_qris` '
            . 'DROP COLUMN `provider`, DROP COLUMN `payment_url`, DROP COLUMN `paid_at`'
        );
    }
};
