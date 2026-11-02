<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarif BRILink per jenis nasabah. Nilainya sengaja ditulis di dalam migrasi
     * (bukan diambil dari model) karena model App\Models\BiayaAdmin sudah dihapus
     * bersama tabel biaya_admin; migrasi lama harus tetap bisa jalan sendiri.
     */
    private function rangeNasabah(string $jenisNasabah): array
    {
        return $jenisNasabah === 'eksternal'
            ? [
                ['min' => 1, 'max' => 100000, 'biaya' => 5000],
                ['min' => 100001, 'max' => 500000, 'biaya' => 8000],
                ['min' => 500001, 'max' => 1000000, 'biaya' => 12000],
                ['min' => 1000001, 'max' => 2000000, 'biaya' => 18000],
                ['min' => 2000001, 'max' => null, 'biaya' => 25000],
            ]
            : [
                ['min' => 1, 'max' => 100000, 'biaya' => 2000],
                ['min' => 100001, 'max' => 500000, 'biaya' => 5000],
                ['min' => 500001, 'max' => 1000000, 'biaya' => 10000],
                ['min' => 1000001, 'max' => 2000000, 'biaya' => 15000],
                ['min' => 2000001, 'max' => null, 'biaya' => 20000],
            ];
    }

    public function up(): void
    {
        Schema::table('biaya_admin', function (Blueprint $table) {
            if (!Schema::hasColumn('biaya_admin', 'jenis_nasabah')) {
                $table->enum('jenis_nasabah', ['internal', 'eksternal'])->nullable()->after('layanan');
                $table->index(['layanan', 'jenis_nasabah']);
            }
        });

        foreach (['internal', 'eksternal'] as $jenisNasabah) {
            DB::table('biaya_admin')->updateOrInsert(
                ['layanan' => 'brilink', 'jenis_nasabah' => $jenisNasabah],
                [
                    'jenis_biaya' => 'range',
                    'nilai' => 0,
                    'aturan_range' => json_encode($this->rangeNasabah($jenisNasabah)),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('biaya_admin')->where('layanan', 'brilink')->whereIn('jenis_nasabah', ['internal', 'eksternal'])->delete();

        Schema::table('biaya_admin', function (Blueprint $table) {
            if (Schema::hasColumn('biaya_admin', 'jenis_nasabah')) {
                $table->dropIndex(['layanan', 'jenis_nasabah']);
                $table->dropColumn('jenis_nasabah');
            }
        });
    }
};
