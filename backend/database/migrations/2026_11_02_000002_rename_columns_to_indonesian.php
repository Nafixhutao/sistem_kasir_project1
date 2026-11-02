<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menyeragamkan nama kolom ke bahasa Indonesia.
 *
 * Yang diganti:
 *  - created_at / updated_at  -> dibuat_pada / diperbarui_pada
 *  - users: name, role, is_active -> nama, peran, aktif
 *  - activity_logs: module, action, description -> modul, aksi, keterangan
 *  - provider -> penyedia (pada tabel transaksi & pembayaran)
 *
 * Tabel bawaan Laravel (migrations, personal_access_tokens,
 * password_reset_tokens, failed_jobs) sengaja TIDAK diubah karena nama
 * kolomnya dipakai langsung oleh kode framework.
 */
return new class extends Migration
{
    /** Tabel aplikasi yang memakai kolom created_at/updated_at. */
    private const TABEL_WAKTU = [
        'activity_logs', 'barang', 'biaya_admin_brilink', 'detail_penjualan',
        'idempotency_records', 'notifikasi', 'pembayaran_qris', 'pembayaran_tagihan',
        'pengeluaran_toko', 'providers', 'retur_pelanggan', 'retur_supplier',
        'suppliers', 'transaksi_ewallet', 'transaksi_penjualan', 'transaksi_pulsa',
        'transaksi_setor_tunai', 'transaksi_tarik_tunai', 'transaksi_transfer', 'users',
    ];

    /** Tabel yang punya kolom "provider". */
    private const TABEL_PROVIDER = [
        'pembayaran_qris', 'pembayaran_tagihan', 'transaksi_ewallet',
        'transaksi_pulsa', 'transaksi_setor_tunai', 'transaksi_tarik_tunai',
        'transaksi_transfer',
    ];

    /** Daftar ganti nama: [tabel, kolom lama, kolom baru]. */
    private function daftar(): array
    {
        $daftar = [];

        foreach (self::TABEL_WAKTU as $tabel) {
            $daftar[] = [$tabel, 'created_at', 'dibuat_pada'];
            $daftar[] = [$tabel, 'updated_at', 'diperbarui_pada'];
        }

        $daftar[] = ['users', 'name', 'nama'];
        $daftar[] = ['users', 'role', 'peran'];
        $daftar[] = ['users', 'is_active', 'aktif'];
        $daftar[] = ['providers', 'is_active', 'aktif'];

        $daftar[] = ['activity_logs', 'user_name', 'nama_pengguna'];
        $daftar[] = ['activity_logs', 'module', 'modul'];
        $daftar[] = ['activity_logs', 'action', 'aksi'];
        $daftar[] = ['activity_logs', 'description', 'keterangan'];
        $daftar[] = ['activity_logs', 'properties', 'properti'];

        foreach (self::TABEL_PROVIDER as $tabel) {
            $daftar[] = [$tabel, 'provider', 'penyedia'];
        }

        return $daftar;
    }

    public function up(): void
    {
        foreach ($this->daftar() as [$tabel, $lama, $baru]) {
            $this->gantiNama($tabel, $lama, $baru);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->daftar()) as [$tabel, $lama, $baru]) {
            $this->gantiNama($tabel, $baru, $lama);
        }
    }

    private function gantiNama(string $tabel, string $dari, string $ke): void
    {
        $kolom = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabel, $dari]
        );

        // Lewati bila tabel atau kolomnya sudah tidak ada (mis. instalasi lama).
        if (!$kolom) {
            return;
        }

        DB::statement("ALTER TABLE `{$tabel}` RENAME COLUMN `{$dari}` TO `{$ke}`");
    }
};
