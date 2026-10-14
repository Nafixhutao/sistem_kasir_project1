<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function preflight(bool $legacy): void
    {
        $db = DB::connection($this->getConnection());
        $columns = [];
        foreach ($db->select('SHOW COLUMNS FROM users') as $column) $columns[$column->Field] = $column;
        $expected = ['id','name','username','password','remember_token','created_at','updated_at'];
        if ($legacy) $expected = array_merge($expected, ['email','email_verified_at']);
        $actual = array_keys($columns); sort($actual); sort($expected);
        if ($actual !== $expected || strtolower($columns['username']->Type) !== 'varchar(50)'
            || $columns['username']->Null !== 'NO') {
            throw new RuntimeException('Unexpected users schema; no DDL performed.');
        }
        if ($db->table('users')->whereNull('username')->orWhereRaw('CHAR_LENGTH(TRIM(username)) = 0')
            ->orWhereRaw('CHAR_LENGTH(username) > 50')->exists()
            || $db->table('users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Invalid/duplicate usernames; no DDL performed.');
        }
        $indexes = [];
        foreach ($db->select('SHOW INDEX FROM users') as $index) $indexes[$index->Key_name][] = $index;
        $usernameIndex = $indexes['users_username_unique'] ?? [];
        if (count($usernameIndex) !== 1 || $usernameIndex[0]->Column_name !== 'username'
            || (int) $usernameIndex[0]->Non_unique !== 0 || $usernameIndex[0]->Sub_part !== null) {
            throw new RuntimeException('Full unique username index required.');
        }
        if ($legacy) {
            $emailIndex = $indexes['users_email_unique'] ?? [];
            if (count($emailIndex) !== 1 || $emailIndex[0]->Column_name !== 'email'
                || (int) $emailIndex[0]->Non_unique !== 0 || $emailIndex[0]->Sub_part !== null
                || $db->table('users')->whereRaw('email IS NULL OR BINARY email <> BINARY username')->exists()) {
                throw new RuntimeException('Legacy index/copy mismatch; inspect before dropping columns.');
            }
            foreach ($indexes as $name => $parts) foreach ($parts as $part) {
                if (in_array($part->Column_name, ['email','email_verified_at'], true) && $name !== 'users_email_unique') {
                    throw new RuntimeException('Unexpected legacy index dependency.');
                }
            }
            $foreignDependency = $db->selectOne("SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE
                WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('email','email_verified_at') AND REFERENCED_TABLE_NAME IS NOT NULL)
                OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME IN ('email','email_verified_at'))");
            if ((int) $foreignDependency->total !== 0) throw new RuntimeException('Legacy foreign key dependency.');
        }
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (!$db->pretending()) $this->preflight(true);
        // One ALTER minimizes partial DDL; MySQL DDL is not a normal transaction.
        $db->statement('ALTER TABLE users DROP INDEX users_email_unique, DROP COLUMN email, DROP COLUMN email_verified_at');
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());
        if (!$db->pretending()) $this->preflight(false);
        $db->statement('ALTER TABLE users ADD COLUMN email VARCHAR(191) NULL AFTER username, ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER email');
        $db->statement('UPDATE users SET email = username');
        if (!$db->pretending() && $db->table('users')->whereRaw('email IS NULL OR BINARY email <> BINARY username')->exists()) {
            throw new RuntimeException('Rollback copy verification failed. Stop; inspect partial schema.');
        }
        // Historical verification timestamps are not recoverable without backup.
        $db->statement('ALTER TABLE users MODIFY email VARCHAR(191) NOT NULL, ADD UNIQUE INDEX users_email_unique (email)');
    }
};
