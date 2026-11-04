<?php

use App\Models\BiayaAdminBrilink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biaya_admin_brilink', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_transaksi', 50);
            $table->enum('jenis_nasabah', ['internal', 'eksternal'])->nullable();
            $table->string('jenis_kartu', 50)->nullable();
            $table->decimal('nominal_min', 15, 2);
            $table->decimal('nominal_max', 15, 2)->nullable();
            $table->decimal('biaya_admin', 15, 2);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
            $table->index(['jenis_transaksi', 'jenis_nasabah', 'nominal_min'], 'biaya_admin_brilink_lookup');
        });

        // Ditulis lewat DB::table memakai nama kolom waktu saat migrasi ini berjalan
        // (created_at/updated_at). Migrasi tidak boleh memakai model karena model
        // sudah memakai nama kolom baru (dibuat_pada/diperbarui_pada).
        $now = now();
        $rows = array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            BiayaAdminBrilink::defaultRows()
        );

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('biaya_admin_brilink')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_admin_brilink');
    }
};
