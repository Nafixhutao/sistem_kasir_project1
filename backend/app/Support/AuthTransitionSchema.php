<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Additive upgrade path; historical migrations remain byte-for-byte unchanged. */
final class AuthTransitionSchema
{
    public static function state(): string
    {
        $c=[]; foreach (DB::select('SHOW COLUMNS FROM users') as $column) $c[$column->Field]=$column;
        $base=['id','nama','password','remember_token','dibuat_pada','diperbarui_pada'];
        foreach (['legacy'=>['email','email_verified_at'],'username'=>['username','email','email_verified_at'],
            'prepared'=>['username','peran','aktif','email','email_verified_at'],'final'=>['username','peran','aktif']] as $state=>$extra) {
            $expected=array_merge($base,$extra); sort($expected); $actual=array_keys($c); sort($actual);
            if ($expected!==$actual) continue;
            if ($c['id']->Type!=='int unsigned' || $c['password']->Type!=='varchar(255)' || $c['remember_token']->Type!=='varchar(100)') throw new RuntimeException('Account column types require review.');
            if ($state!=='legacy') {
                if ($c['username']->Type!=='varchar(50)' || $c['username']->Null!=='NO' || $c['nama']->Type!=='varchar(50)') throw new RuntimeException('Username schema requires review.');
                self::unique('users_username_unique','username');
                if (DB::table('users')->whereNull('username')->orWhereRaw('CHAR_LENGTH(TRIM(username))=0 OR CHAR_LENGTH(username)>50')->exists()) throw new RuntimeException('Username data invalid.');
            }
            if (isset($c['email'])) {
                $emailNullable=$state==='prepared' ? 'YES' : 'NO';
                if ($c['email']->Type!=='varchar(191)' || $c['email']->Null!==$emailNullable
                    || $c['email_verified_at']->Type!=='timestamp' || $c['email_verified_at']->Null!=='YES') throw new RuntimeException('Legacy schema requires review.');
                self::unique('users_email_unique','email');
                if ($state!=='legacy' && DB::table('users')->whereNotNull('email')->whereRaw('BINARY email <> BINARY username')->exists()) throw new RuntimeException('Legacy login copy mismatch.');
            }
            if (in_array($state,['prepared','final'],true)) {
                if ($c['peran']->Type!=="enum('kasir','admin')" || $c['peran']->Null!=='NO' || $c['peran']->Default!=='kasir'
                    || $c['aktif']->Type!=='tinyint(1)' || $c['aktif']->Null!=='NO' || (string)$c['aktif']->Default!=='1'
                    || DB::table('users')->whereNotIn('peran',['kasir','admin'])->orWhereNotIn('aktif',[0,1])->exists()) throw new RuntimeException('Role/status schema requires review.');
            }
            return $state;
        }
        throw new RuntimeException('Unexpected account schema; stop before DDL.');
    }

    private static function unique(string $name,string $column): void
    {
        $parts=array_values(array_filter(DB::select('SHOW INDEX FROM users'),fn($r)=>$r->Key_name===$name));
        if (count($parts)!==1 || $parts[0]->Column_name!==$column || (int)$parts[0]->Non_unique!==0 || $parts[0]->Sub_part!==null) throw new RuntimeException('Required full unique index missing.');
    }

    public static function prepare(): void
    {
        if (!DB::connection()->pretending()) {
            if (self::state()!=='username' || DB::table('users')->whereNull('email')->exists()) throw new RuntimeException('Prepare requires verified legacy login copy.');
        }
        // Existing values and timestamps are untouched. Nullable legacy email lets
        // the final username-only factory/API/command create accounts during transition.
        DB::statement("ALTER TABLE users ADD peran ENUM('kasir','admin') NOT NULL DEFAULT 'kasir' AFTER username, ADD aktif BOOLEAN NOT NULL DEFAULT TRUE AFTER peran, MODIFY email VARCHAR(191) NULL");
    }

    public static function finalize(): void
    {
        if (!DB::connection()->pretending()) {
            if (self::state()!=='prepared') throw new RuntimeException('Roles/status must exist before email removal.');
            foreach (DB::select('SHOW INDEX FROM users') as $index) if (in_array($index->Column_name,['email','email_verified_at'],true) && $index->Key_name!=='users_email_unique') throw new RuntimeException('Unexpected legacy index dependency.');
            $dependency=DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ('email','email_verified_at') AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME IN ('email','email_verified_at'))");
            if ((int)$dependency->n!==0) throw new RuntimeException('Legacy foreign key dependency requires review.');
        }
        DB::statement('ALTER TABLE users DROP INDEX users_email_unique, DROP COLUMN email, DROP COLUMN email_verified_at');
    }

    public static function undoFinalize(): void
    {
        if (!DB::connection()->pretending() && self::state()!=='final') throw new RuntimeException('Unexpected rollback schema.');
        DB::statement('ALTER TABLE users ADD email VARCHAR(191) NULL AFTER username, ADD email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER email');
        DB::statement('UPDATE users SET email=username');
        if (!DB::connection()->pretending() && DB::table('users')->whereRaw('email IS NULL OR BINARY email <> BINARY username')->exists()) throw new RuntimeException('Rollback copy mismatch; inspect partial schema.');
        DB::statement('ALTER TABLE users ADD UNIQUE INDEX users_email_unique (email)');
    }

    public static function undoPrepare(): void
    {
        if (!DB::connection()->pretending() && (self::state()!=='prepared' || DB::table('users')->where('peran','<>','kasir')->orWhere('aktif',false)->orWhereNull('email')->exists())) throw new RuntimeException('Rollback would lose account authorization/legacy login state.');
        DB::statement('ALTER TABLE users DROP COLUMN aktif, DROP COLUMN peran, MODIFY email VARCHAR(191) NOT NULL');
    }
}
