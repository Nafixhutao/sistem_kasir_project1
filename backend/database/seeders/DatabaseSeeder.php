<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
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

        // Tarif semua layanan (termasuk BRILink internal/eksternal) disimpan di
        // biaya_admin_brilink. Tabel biaya_admin versi lama sudah dihapus.
        if (Schema::hasTable('biaya_admin_brilink')) {
            BiayaAdminBrilink::ensureDefaults();
        }

        if (Schema::hasTable('providers')) {
            Provider::ensureDefaults();
        }
    }
}
