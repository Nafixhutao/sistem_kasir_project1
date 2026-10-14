<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function preflight(bool $up): void
    {
        $column = DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barang' AND COLUMN_NAME='id'");
        if (!$column || $column->COLUMN_TYPE !== 'int unsigned') throw new RuntimeException('Unexpected Barang ID schema.');
        if ($up) {
            if (Schema::hasTable('suppliers') || Schema::hasColumn('barang', 'supplier_id')) throw new RuntimeException('Supplier schema already exists; inspect before continuing.');
        } else {
            if (!Schema::hasTable('suppliers') || !Schema::hasColumn('barang', 'supplier_id')
                || DB::table('suppliers')->exists() || DB::table('barang')->whereNotNull('supplier_id')->exists()) {
                throw new RuntimeException('Rollback refused: supplier data must be preserved.');
            }
        }
    }
    public function up(): void
    {
        if (!DB::connection()->pretending()) $this->preflight(true);
        Schema::create('suppliers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_supplier', 150);
            $table->string('no_telepon', 30)->nullable();
            $table->string('alamat', 255)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->timestamps();
        });
        Schema::table('barang', function (Blueprint $table) {
            $table->unsignedInteger('supplier_id')->nullable();
            $table->index('supplier_id', 'barang_supplier_id_index');
            $table->foreign('supplier_id', 'barang_supplier_id_foreign')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        if (!DB::connection()->pretending()) $this->preflight(false);
        Schema::table('barang', function (Blueprint $table) {
            $table->dropForeign('barang_supplier_id_foreign');
            $table->dropIndex('barang_supplier_id_index');
            $table->dropColumn('supplier_id');
        });
        Schema::dropIfExists('suppliers');
    }
};
