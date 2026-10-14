<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function preflight(bool $up): void
    {
        $column=DB::selectOne("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'");
        $expected=$up ? "enum('kasir','pemilik')" : "enum('kasir','admin')";
        $roles=$up ? ['kasir','pemilik'] : ['kasir','admin'];
        if (!$column || $column->COLUMN_TYPE!==$expected || $column->IS_NULLABLE!=='NO' || $column->COLUMN_DEFAULT!=='kasir'
            || DB::table('users')->whereNull('role')->orWhereNotIn('role',$roles)->exists()) {
            throw new RuntimeException('Unexpected role schema/data; no DDL performed.');
        }
    }
    public function up(): void
    {
        if (!DB::connection()->pretending()) $this->preflight(true);
        // Expand first to prevent ENUM truncation. Raw SQL preserves account timestamps.
        DB::statement("ALTER TABLE users MODIFY role ENUM('kasir','pemilik','admin') NOT NULL DEFAULT 'kasir'");
        DB::statement("UPDATE users SET role='admin' WHERE role='pemilik'");
        DB::statement("ALTER TABLE users MODIFY role ENUM('kasir','admin') NOT NULL DEFAULT 'kasir'");
    }
    public function down(): void
    {
        if (!DB::connection()->pretending()) $this->preflight(false);
        DB::statement("ALTER TABLE users MODIFY role ENUM('kasir','pemilik','admin') NOT NULL DEFAULT 'kasir'");
        DB::statement("UPDATE users SET role='pemilik' WHERE role='admin'");
        DB::statement("ALTER TABLE users MODIFY role ENUM('kasir','pemilik') NOT NULL DEFAULT 'kasir'");
    }
};
