<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (!DB::connection()->pretending()) $this->validateUp();
        DB::statement("ALTER TABLE users ADD role ENUM('kasir','pemilik') NOT NULL DEFAULT 'kasir' AFTER username, ADD is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER role");
    }
    public function validateUp(): void
    {
        if (!Schema::hasColumn('users','username') || Schema::hasColumn('users','email') || Schema::hasColumn('users','role') || Schema::hasColumn('users','is_active')) throw new RuntimeException('Unexpected users schema; no DDL performed.');
        $column = DB::selectOne("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='username'");
        $index = DB::select("SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='users_username_unique'");
        if ($column->COLUMN_TYPE !== 'varchar(50)' || $column->IS_NULLABLE !== 'NO' || count($index) !== 1 || $index[0]->COLUMN_NAME !== 'username' || (int)$index[0]->NON_UNIQUE !== 0 || $index[0]->SUB_PART !== null || DB::table('users')->whereRaw("CHAR_LENGTH(username) = 0 OR CHAR_LENGTH(username) > 50")->exists()) throw new RuntimeException('Username schema/data invalid; no DDL performed.');
    }
    public function down(): void
    {
        if (!DB::connection()->pretending() && (!Schema::hasColumn('users','role') || !Schema::hasColumn('users','is_active') || DB::table('users')->where('role','<>','kasir')->orWhere('is_active',false)->exists())) throw new RuntimeException('Rollback refused: owner/inactive accounts must not lose authorization state.');
        DB::statement('ALTER TABLE users DROP COLUMN is_active, DROP COLUMN role');
    }
};
