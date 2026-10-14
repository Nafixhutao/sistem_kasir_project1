<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        if (!$db->pretending()) {
            $columns = array_column($db->select('SHOW COLUMNS FROM users'), 'Field');
            if (!in_array('email', $columns, true) || in_array('username', $columns, true)) {
                throw new RuntimeException('Unexpected users schema; inspect before retrying.');
            }
            $invalid = $db->table('users')->whereNull('email')
                ->orWhereRaw("CHAR_LENGTH(TRIM(email)) = 0")
                ->orWhereRaw('CHAR_LENGTH(email) > 50')
                ->orWhereRaw('CHAR_LENGTH(name) > 50')->exists();
            $duplicate = $db->table('users')->select('email')->groupBy('email')
                ->havingRaw('COUNT(*) > 1')->exists();
            if ($invalid || $duplicate) {
                throw new RuntimeException('Username/name preflight failed; no schema changes made.');
            }
        }
        $db->statement('ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER name');
        $db->statement('UPDATE users SET username = email');
        if (!$db->pretending() && $db->table('users')->whereRaw('username IS NULL OR BINARY username <> BINARY email')->exists()) {
            throw new RuntimeException('Copy verification failed; email retained. Inspect before continuing.');
        }
        $db->statement('ALTER TABLE users MODIFY username VARCHAR(50) NOT NULL, ADD UNIQUE INDEX users_username_unique (username), MODIFY name VARCHAR(50) NOT NULL');
    }

    public function down(): void
    {
        // Only this migration is reversed; password, users and legacy columns survive.
        DB::statement('ALTER TABLE users DROP INDEX users_username_unique, DROP COLUMN username, MODIFY name VARCHAR(100) NOT NULL');
    }
};
