<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaNormalizationTest extends TestCase
{
    private const BUSINESS_TABLES = [
        'users', 'barang', 'transaksi_penjualan', 'detail_penjualan', 'pembayaran_qris',
        'transaksi_transfer', 'transaksi_tarik_tunai', 'transaksi_setor_tunai',
        'pembayaran_tagihan', 'transaksi_pulsa', 'transaksi_ewallet', 'biaya_admin',
        'biaya_admin_brilink', 'providers', 'pengeluaran_toko',
        'retur_pelanggan', 'retur_supplier', 'activity_logs', 'notifikasi',
    ];

    private const VARCHAR_TARGETS = [
        'users' => ['name' => 50, 'username' => 50, 'password' => 255, 'remember_token' => 100],
        'barang' => ['kode_barang' => 50, 'nama_barang' => 150, 'kategori' => 100],
        'transaksi_penjualan' => ['kode_transaksi' => 50, 'kasir' => 100],
        'detail_penjualan' => ['kode_barang' => 50, 'nama_barang' => 150],
        'pembayaran_qris' => ['order_id' => 100, 'snap_token' => 255, 'transaction_id' => 100],
        'transaksi_transfer' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_kartu' => 50, 'jenis_transfer' => 50, 'bank_tujuan' => 100, 'nomor_rekening_tujuan' => 30, 'nama_penerima' => 100, 'kasir' => 100],
        'transaksi_tarik_tunai' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_kartu' => 50, 'nomor_rekening' => 30, 'nama_penerima' => 100, 'nomor_hp' => 20, 'kasir' => 100],
        'transaksi_setor_tunai' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_kartu' => 50, 'nomor_rekening_tujuan' => 30, 'nama_pemilik_rekening' => 100, 'bank_tujuan' => 100, 'sumber_dana' => 100, 'kasir' => 100],
        'pembayaran_tagihan' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_kartu' => 50, 'nomor_pelanggan' => 50, 'nama_pelanggan' => 100, 'kasir' => 100],
        'transaksi_pulsa' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_kartu' => 50, 'operator' => 50, 'nomor_tujuan' => 20, 'produk' => 150, 'kasir' => 100],
        'transaksi_ewallet' => ['kode_transaksi' => 50, 'provider' => 100, 'jenis_ewallet' => 100, 'nomor_ewallet' => 30, 'nama_customer' => 100, 'kasir' => 100],
        'biaya_admin' => ['layanan' => 50],
        'biaya_admin_brilink' => ['jenis_transaksi' => 50, 'jenis_kartu' => 50],
        'providers' => ['nama_provider' => 100],
        'pengeluaran_toko' => ['kategori' => 100, 'nama_pengeluaran' => 150, 'kasir' => 100],
        'retur_pelanggan' => ['nomor_retur' => 50, 'kode_transaksi' => 50, 'kode_barang' => 50, 'nama_barang' => 150, 'alasan_retur' => 255, 'metode_pengembalian_dana' => 50],
        'retur_supplier' => ['nomor_retur' => 50, 'nama_supplier' => 150, 'kode_barang' => 50, 'nama_barang' => 150, 'kasir' => 100],
        'activity_logs' => ['user_name' => 100, 'module' => 50, 'action' => 50],
        'notifikasi' => ['tipe' => 50, 'judul' => 150, 'referensi_tipe' => 100, 'url' => 255, 'fingerprint' => 191],
    ];

    private const UNCHANGED_VARCHAR_COLUMNS = [
        'users.password', 'users.remember_token', 'pembayaran_qris.snap_token',
        'transaksi_transfer.provider', 'transaksi_tarik_tunai.provider',
        'transaksi_setor_tunai.provider', 'pembayaran_tagihan.provider',
        'transaksi_pulsa.provider', 'transaksi_ewallet.jenis_ewallet',
        'biaya_admin_brilink.jenis_transaksi', 'biaya_admin_brilink.jenis_kartu',
        'providers.nama_provider', 'retur_pelanggan.alasan_retur',
        'notifikasi.url',
    ];

    private const SPECIAL_VARCHAR_MODIFIERS = [
        'activity_logs.user_name' => ['NO', 'admin'],
        'pembayaran_qris.transaction_id' => ['YES', null],
        'pembayaran_tagihan.jenis_kartu' => ['NO', 'Kartu Nasabah'],
        'pembayaran_tagihan.kasir' => ['NO', 'admin'],
        'pengeluaran_toko.kasir' => ['NO', 'admin'],
        'retur_supplier.kasir' => ['NO', 'admin'],
        'transaksi_ewallet.kasir' => ['NO', 'admin'],
        'transaksi_ewallet.nama_customer' => ['YES', null],
        'transaksi_penjualan.kasir' => ['NO', 'admin'],
        'transaksi_pulsa.jenis_kartu' => ['NO', 'Kartu Nasabah'],
        'transaksi_pulsa.kasir' => ['NO', 'admin'],
        'transaksi_setor_tunai.bank_tujuan' => ['YES', null],
        'transaksi_setor_tunai.jenis_kartu' => ['NO', 'Kartu Nasabah'],
        'transaksi_setor_tunai.kasir' => ['NO', 'admin'],
        'transaksi_setor_tunai.nama_pemilik_rekening' => ['YES', null],
        'transaksi_setor_tunai.nomor_rekening_tujuan' => ['YES', null],
        'transaksi_setor_tunai.sumber_dana' => ['YES', null],
        'transaksi_tarik_tunai.jenis_kartu' => ['NO', 'Kartu Nasabah'],
        'transaksi_tarik_tunai.kasir' => ['NO', 'admin'],
        'transaksi_transfer.bank_tujuan' => ['YES', null],
        'transaksi_transfer.jenis_kartu' => ['NO', 'Kartu Nasabah'],
        'transaksi_transfer.kasir' => ['NO', 'admin'],
        'notifikasi.referensi_tipe' => ['YES', null],
        'notifikasi.url' => ['YES', null],
    ];

    private const REQUIRED_INDEXES = [
        'users.users_username_unique' => [['username'], true],
        'barang.barang_kode_barang_unique' => [['kode_barang'], true],
        'transaksi_penjualan.transaksi_penjualan_kode_transaksi_unique' => [['kode_transaksi'], true],
        'pembayaran_qris.pembayaran_qris_order_id_unique' => [['order_id'], true],
        'transaksi_transfer.transaksi_transfer_kode_transaksi_unique' => [['kode_transaksi'], true],
        'transaksi_tarik_tunai.transaksi_tarik_tunai_kode_transaksi_unique' => [['kode_transaksi'], true],
        'transaksi_setor_tunai.transaksi_setor_tunai_kode_transaksi_unique' => [['kode_transaksi'], true],
        'pembayaran_tagihan.pembayaran_tagihan_kode_transaksi_unique' => [['kode_transaksi'], true],
        'transaksi_pulsa.transaksi_pulsa_kode_transaksi_unique' => [['kode_transaksi'], true],
        'transaksi_ewallet.transaksi_ewallet_kode_transaksi_unique' => [['kode_transaksi'], true],
        'providers.providers_nama_provider_unique' => [['nama_provider'], true],
        'retur_pelanggan.retur_pelanggan_nomor_retur_unique' => [['nomor_retur'], true],
        'retur_supplier.retur_supplier_nomor_retur_unique' => [['nomor_retur'], true],
        'biaya_admin.biaya_admin_layanan_jenis_nasabah_index' => [['layanan', 'jenis_nasabah'], false],
        'biaya_admin_brilink.biaya_admin_brilink_lookup' => [['jenis_transaksi', 'jenis_nasabah', 'nominal_min'], false],
        'notifikasi.notifikasi_user_fingerprint_unique' => [['user_id', 'fingerprint'], true],
        'notifikasi.notifikasi_user_read_created_index' => [['user_id', 'dibaca_at', 'created_at'], false],
        'notifikasi.notifikasi_referensi_index' => [['referensi_tipe', 'referensi_id'], false],
        'notifikasi.notifikasi_tipe_index' => [['tipe'], false],
    ];

    public function test_business_keys_and_varchar_lengths_match_the_normalized_schema(): void
    {
        $businessTableCount = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', self::BUSINESS_TABLES)
            ->count();
        $this->assertSame(19, $businessTableCount);

        foreach (self::BUSINESS_TABLES as $table) {
            $column = $this->column($table, 'id');
            $this->assertSame('int unsigned', strtolower($column->COLUMN_TYPE), "{$table}.id");
            $this->assertSame('NO', $column->IS_NULLABLE, "{$table}.id nullable");
            $this->assertSame('PRI', $column->COLUMN_KEY, "{$table}.id primary");
            $this->assertStringContainsString('auto_increment', $column->EXTRA, "{$table}.id auto increment");
        }

        foreach (self::VARCHAR_TARGETS as $table => $columns) {
            foreach ($columns as $column => $length) {
                $metadata = $this->column($table, $column);
                $this->assertSame('varchar', strtolower($metadata->DATA_TYPE), "{$table}.{$column} type");
                $this->assertSame($length, (int) $metadata->CHARACTER_MAXIMUM_LENGTH, "{$table}.{$column} length");

                $maxLength = DB::table($table)->max(DB::raw("CHAR_LENGTH(`{$column}`)"));
                $this->assertLessThanOrEqual($length, (int) ($maxLength ?? 0), "{$table}.{$column} data length");

                $key = "{$table}.{$column}";
                if (!in_array($key, self::UNCHANGED_VARCHAR_COLUMNS, true)) {
                    [$nullable, $default] = self::SPECIAL_VARCHAR_MODIFIERS[$key] ?? ['NO', null];
                    $this->assertSame($nullable, $metadata->IS_NULLABLE, "{$key} nullable");
                    $this->assertSame($default, $metadata->COLUMN_DEFAULT, "{$key} default");
                }
            }
        }

        $remaining255 = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', self::BUSINESS_TABLES)
            ->where('DATA_TYPE', 'varchar')
            ->where('CHARACTER_MAXIMUM_LENGTH', 255)
            ->orderBy('TABLE_NAME')->orderBy('COLUMN_NAME')
            ->get(['TABLE_NAME', 'COLUMN_NAME'])
            ->map(fn ($column) => "{$column->TABLE_NAME}.{$column->COLUMN_NAME}")
            ->all();
        $this->assertSame([
            'notifikasi.url',
            'pembayaran_qris.snap_token',
            'retur_pelanggan.alasan_retur',
            'users.password',
        ], $remaining255);
    }

    public function test_all_business_foreign_keys_keep_their_rules(): void
    {
        $expected = [
            'detail_penjualan_barang_id_foreign' => ['detail_penjualan', 'barang_id', 'barang', 'NO ACTION', 'NO'],
            'detail_penjualan_transaksi_penjualan_id_foreign' => ['detail_penjualan', 'transaksi_penjualan_id', 'transaksi_penjualan', 'CASCADE', 'NO'],
            'pembayaran_qris_transaksi_penjualan_id_foreign' => ['pembayaran_qris', 'transaksi_penjualan_id', 'transaksi_penjualan', 'NO ACTION', 'NO'],
            'retur_pelanggan_barang_id_foreign' => ['retur_pelanggan', 'barang_id', 'barang', 'SET NULL', 'YES'],
            'retur_pelanggan_detail_penjualan_id_foreign' => ['retur_pelanggan', 'detail_penjualan_id', 'detail_penjualan', 'SET NULL', 'YES'],
            'retur_pelanggan_transaksi_penjualan_id_foreign' => ['retur_pelanggan', 'transaksi_penjualan_id', 'transaksi_penjualan', 'SET NULL', 'YES'],
            'retur_supplier_barang_id_foreign' => ['retur_supplier', 'barang_id', 'barang', 'NO ACTION', 'NO'],
            'notifikasi_user_id_foreign' => ['notifikasi', 'user_id', 'users', 'CASCADE', 'NO'],
            'barang_supplier_id_foreign' => ['barang', 'supplier_id', 'suppliers', 'RESTRICT', 'YES'],
        ];

        $actualForeignKeyCount = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->count();
        $this->assertSame(9, $actualForeignKeyCount);

        foreach ($expected as $name => [$table, $column, $referencedTable, $deleteRule, $nullable]) {
            $foreignKey = DB::selectOne(
                'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                 WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.CONSTRAINT_NAME = ?',
                [$name]
            );
            $this->assertNotNull($foreignKey, $name);
            $this->assertSame($table, $foreignKey->TABLE_NAME);
            $this->assertSame($column, $foreignKey->COLUMN_NAME);
            $this->assertSame($referencedTable, $foreignKey->REFERENCED_TABLE_NAME);
            $this->assertSame($deleteRule, $foreignKey->DELETE_RULE);
            $this->assertSame('NO ACTION', $foreignKey->UPDATE_RULE);
            $columnMetadata = $this->column($table, $column);
            $this->assertSame('int unsigned', strtolower($columnMetadata->COLUMN_TYPE));
            $this->assertSame($nullable, $columnMetadata->IS_NULLABLE);
        }
    }

    public function test_indexes_modifiers_non_varchar_types_and_relations_are_preserved(): void
    {
        foreach (self::REQUIRED_INDEXES as $key => [$expectedColumns, $unique]) {
            [$table, $name] = explode('.', $key, 2);
            $rows = DB::select(
                'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                 ORDER BY SEQ_IN_INDEX',
                [$table, $name]
            );
            $this->assertSame($expectedColumns, array_map(fn ($row) => $row->COLUMN_NAME, $rows), $key);
            $this->assertNotEmpty($rows, $key);
            $this->assertSame($unique ? 0 : 1, (int) $rows[0]->NON_UNIQUE, "{$key} uniqueness");
        }

        $typeCounts = DB::select(
            "SELECT DATA_TYPE, COLUMN_TYPE, COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (".$this->placeholders(self::BUSINESS_TABLES).")
               AND DATA_TYPE IN ('decimal','json','text','date','timestamp','tinyint')
             GROUP BY DATA_TYPE, COLUMN_TYPE",
            self::BUSINESS_TABLES
        );
        $actualTypes = collect($typeCounts)->mapWithKeys(
            fn ($row) => ["{$row->DATA_TYPE}|{$row->COLUMN_TYPE}" => (int) $row->total]
        )->all();
        $this->assertSame(32, $actualTypes['decimal|decimal(15,2)'] ?? 0);
        $this->assertSame(3, $actualTypes['json|json'] ?? 0);
        $this->assertSame(10, $actualTypes['text|text'] ?? 0);
        $this->assertSame(10, $actualTypes['date|date'] ?? 0);
        $usernameComplete = DB::table('migrations')->where('migration', '2026_10_08_000001_remove_legacy_email_from_users')->exists();
        // The only removed non-varchar column is legacy users.email_verified_at.
        $this->assertSame($usernameComplete ? 40 : 41, $actualTypes['timestamp|timestamp'] ?? 0);
        $roleComplete = \Illuminate\Support\Facades\Schema::hasColumn('users','is_active');
        $this->assertSame($roleComplete ? 5 : 4, $actualTypes['tinyint|tinyint(1)'] ?? 0);

        $orphanChecks = [
            ['detail_penjualan', 'transaksi_penjualan_id', 'transaksi_penjualan', false],
            ['detail_penjualan', 'barang_id', 'barang', false],
            ['pembayaran_qris', 'transaksi_penjualan_id', 'transaksi_penjualan', true],
            ['retur_pelanggan', 'transaksi_penjualan_id', 'transaksi_penjualan', true],
            ['retur_pelanggan', 'detail_penjualan_id', 'detail_penjualan', true],
            ['retur_pelanggan', 'barang_id', 'barang', true],
            ['retur_supplier', 'barang_id', 'barang', false],
            ['notifikasi', 'user_id', 'users', false],
        ];
        foreach ($orphanChecks as [$child, $column, $parent, $nullable]) {
            $nullableClause = $nullable ? " AND child.`{$column}` IS NOT NULL" : '';
            $result = DB::selectOne(
                "SELECT COUNT(*) AS total FROM `{$child}` child
                 LEFT JOIN `{$parent}` parent ON parent.`id` = child.`{$column}`
                 WHERE parent.`id` IS NULL{$nullableClause}"
            );
            $this->assertSame(0, (int) $result->total, "Orphan {$child}.{$column}");
        }
    }

    private function column(string $table, string $column): object
    {
        $metadata = DB::selectOne(
            'SELECT COLUMN_TYPE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        $this->assertNotNull($metadata, "Metadata {$table}.{$column}");

        return $metadata;
    }

    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
