<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// biaya_admin adalah konfigurasi biaya versi lama. Sejak 16 Juni 2026 seluruh
// perhitungan biaya memakai biaya_admin_brilink (mencakup e-wallet, pulsa/paket
// data, transfer, tarik tunai, setor tunai, tagihan, dan BRILink), sehingga
// tabel ini tidak lagi dibaca maupun ditulis oleh kode aplikasi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('biaya_admin');
    }

    public function down(): void
    {
        Schema::create('biaya_admin', function (Blueprint $table) {
            $table->id();
            $table->string('layanan', 50);
            $table->string('jenis_nasabah', 20)->nullable();
            $table->string('jenis_biaya', 20)->default('flat');
            $table->decimal('nilai', 15, 2)->default(0);
            $table->json('aturan_range')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['layanan', 'jenis_nasabah']);
        });
    }
};
