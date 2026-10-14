<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\BiayaAdmin;
use App\Models\BiayaAdminBrilink;
use App\Models\Provider;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['username' => 'admin'], [
            'name' => 'Admin Sultan Cell',
            'password' => Hash::make('admin123'),
        ]);

        $rangeAdminInternal = [
            ['min' => 1, 'max' => 100000, 'biaya' => 2000],
            ['min' => 100001, 'max' => 500000, 'biaya' => 5000],
            ['min' => 500001, 'max' => 1000000, 'biaya' => 10000],
            ['min' => 1000001, 'max' => 2000000, 'biaya' => 15000],
            ['min' => 2000001, 'max' => null, 'biaya' => 20000],
        ];

        $rangeAdminEksternal = [
            ['min' => 1, 'max' => 100000, 'biaya' => 5000],
            ['min' => 100001, 'max' => 500000, 'biaya' => 8000],
            ['min' => 500001, 'max' => 1000000, 'biaya' => 12000],
            ['min' => 1000001, 'max' => 2000000, 'biaya' => 18000],
            ['min' => 2000001, 'max' => null, 'biaya' => 25000],
        ];

        $biayaAdmin = [
            ['layanan' => 'transfer', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'tarik_tunai', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'setor_tunai', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'tagihan', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'pulsa', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'paket_data', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
            ['layanan' => 'ewallet', 'jenis_biaya' => 'range', 'nilai' => 0, 'aturan_range' => $rangeAdminInternal],
        ];

        foreach ($biayaAdmin as $item) {
            BiayaAdmin::updateOrCreate(['layanan' => $item['layanan']], $item);
        }

        foreach ([
            ['jenis_nasabah' => 'internal', 'aturan_range' => $rangeAdminInternal],
            ['jenis_nasabah' => 'eksternal', 'aturan_range' => $rangeAdminEksternal],
        ] as $item) {
            BiayaAdmin::updateOrCreate(
                ['layanan' => 'brilink', 'jenis_nasabah' => $item['jenis_nasabah']],
                [
                    'jenis_biaya' => 'range',
                    'nilai' => 0,
                    'aturan_range' => $item['aturan_range'],
                    'is_active' => true,
                ]
            );
        }

        if (Schema::hasTable('biaya_admin_brilink')) {
            BiayaAdminBrilink::ensureDefaults();
        }

        if (Schema::hasTable('providers')) {
            Provider::ensureDefaults();
        }
    }
}
