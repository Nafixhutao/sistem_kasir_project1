<?php

namespace App\Models;


use Illuminate\Support\Facades\Schema;

class BiayaAdminBrilink extends BaseModel
{
    protected $table = 'biaya_admin_brilink';

    protected $fillable = [
        'jenis_transaksi',
        'jenis_nasabah',
        'jenis_kartu',
        'nominal_min',
        'nominal_max',
        'biaya_admin',
        'aktif',
    ];

    protected $casts = [
        'nominal_min' => 'float',
        'nominal_max' => 'float',
        'biaya_admin' => 'float',
        'aktif' => 'boolean',
    ];

    public const JENIS_NASABAH = ['internal', 'eksternal'];
    public const NASABAH_SERVICES = ['transfer', 'tarik_tunai', 'setor_tunai', 'tagihan', 'pulsa_paket_data'];
    public const EWALLET_SERVICE = 'ewallet';
    public const LAYANAN = [
        'transfer' => 'Transfer',
        'tarik_tunai' => 'Tarik Tunai',
        'setor_tunai' => 'Setor Tunai',
        'tagihan' => 'Pembayaran Tagihan',
        'pulsa_paket_data' => 'Pulsa & Paket Data',
        'ewallet' => 'Top Up / Cair E-Wallet',
    ];

    public static function layanan(): array
    {
        return self::LAYANAN;
    }

    public static function usesNasabah(string $jenisTransaksi): bool
    {
        return in_array($jenisTransaksi, self::NASABAH_SERVICES, true);
    }

    public static function jenisKartuNasabah(string $jenisNasabah): string
    {
        return $jenisNasabah === 'eksternal' ? 'Kartu Konter' : 'Kartu Nasabah';
    }

    public static function defaultRangeInternal(): array
    {
        return [
            ['nominal_min' => 1, 'nominal_max' => 100000, 'biaya_admin' => 2000],
            ['nominal_min' => 100001, 'nominal_max' => 500000, 'biaya_admin' => 5000],
            ['nominal_min' => 500001, 'nominal_max' => 1000000, 'biaya_admin' => 10000],
            ['nominal_min' => 1000001, 'nominal_max' => 2000000, 'biaya_admin' => 15000],
            ['nominal_min' => 2000001, 'nominal_max' => null, 'biaya_admin' => 20000],
        ];
    }

    public static function defaultRangeEksternal(): array
    {
        return [
            ['nominal_min' => 1, 'nominal_max' => 100000, 'biaya_admin' => 5000],
            ['nominal_min' => 100001, 'nominal_max' => 500000, 'biaya_admin' => 8000],
            ['nominal_min' => 500001, 'nominal_max' => 1000000, 'biaya_admin' => 12000],
            ['nominal_min' => 1000001, 'nominal_max' => 2000000, 'biaya_admin' => 18000],
            ['nominal_min' => 2000001, 'nominal_max' => null, 'biaya_admin' => 25000],
        ];
    }

    public static function defaultRange(?string $jenisNasabah = null): array
    {
        return $jenisNasabah === 'eksternal'
            ? self::defaultRangeEksternal()
            : self::defaultRangeInternal();
    }

    public static function hitung(string $jenisTransaksi, float $nominal, ?string $jenisNasabah = null): float
    {
        $jenisNasabah = self::usesNasabah($jenisTransaksi)
            ? self::normalizeJenisNasabah($jenisNasabah)
            : null;

        self::ensureDefaults();

        $query = self::query()
            ->where('jenis_transaksi', $jenisTransaksi)
            ->where('aktif', true)
            ->where('nominal_min', '<=', $nominal)
            ->where(function ($range) use ($nominal) {
                $range->whereNull('nominal_max')->orWhere('nominal_max', '>=', $nominal);
            });

        if (self::usesNasabah($jenisTransaksi)) {
            $query->where('jenis_nasabah', $jenisNasabah);
        } else {
            $query->whereNull('jenis_nasabah');
        }

        return (float) ($query->orderBy('nominal_min')->value('biaya_admin') ?? 0);
    }

    public static function normalizeJenisNasabah(?string $jenisNasabah): string
    {
        return $jenisNasabah === 'eksternal' ? 'eksternal' : 'internal';
    }

    public static function ensureDefaults(): void
    {
        if (!Schema::hasTable('biaya_admin_brilink')) {
            return;
        }

        foreach (self::NASABAH_SERVICES as $jenisTransaksi) {
            foreach (self::JENIS_NASABAH as $jenisNasabah) {
                self::ensureDefaultGroup($jenisTransaksi, $jenisNasabah);
            }
        }

        self::ensureDefaultGroup(self::EWALLET_SERVICE, null);
    }

    /**
     * Seluruh baris tarif bawaan (tanpa kolom waktu).
     *
     * Dipakai oleh seeder dan juga oleh migrasi pembuatan tabel. Migrasi sengaja
     * memakai daftar ini lewat DB::table (bukan model) karena pada saat migrasi
     * berjalan nama kolom waktu masih created_at/updated_at.
     */
    public static function defaultRows(): array
    {
        $rows = [];

        foreach (self::NASABAH_SERVICES as $jenisTransaksi) {
            foreach (self::JENIS_NASABAH as $jenisNasabah) {
                $rows = array_merge($rows, self::defaultRowsFor($jenisTransaksi, $jenisNasabah));
            }
        }

        return array_merge($rows, self::defaultRowsFor(self::EWALLET_SERVICE, null));
    }

    private static function defaultRowsFor(string $jenisTransaksi, ?string $jenisNasabah): array
    {
        return array_map(fn (array $range) => [
            'jenis_transaksi' => $jenisTransaksi,
            'jenis_nasabah' => $jenisNasabah,
            'jenis_kartu' => $jenisNasabah ? self::jenisKartuNasabah($jenisNasabah) : null,
            'nominal_min' => $range['nominal_min'],
            'nominal_max' => $range['nominal_max'],
            'biaya_admin' => $range['biaya_admin'],
            'aktif' => true,
        ], self::defaultRange($jenisNasabah));
    }

    private static function ensureDefaultGroup(string $jenisTransaksi, ?string $jenisNasabah): void
    {
        $exists = self::query()
            ->where('jenis_transaksi', $jenisTransaksi)
            ->when($jenisNasabah === null, fn($query) => $query->whereNull('jenis_nasabah'), fn($query) => $query->where('jenis_nasabah', $jenisNasabah))
            ->exists();

        if ($exists) {
            return;
        }

        foreach (self::defaultRange($jenisNasabah) as $range) {
            self::create([
                'jenis_transaksi' => $jenisTransaksi,
                'jenis_nasabah' => $jenisNasabah,
                'jenis_kartu' => $jenisNasabah ? self::jenisKartuNasabah($jenisNasabah) : null,
                'nominal_min' => $range['nominal_min'],
                'nominal_max' => $range['nominal_max'],
                'biaya_admin' => $range['biaya_admin'],
                'aktif' => true,
            ]);
        }
    }
}
