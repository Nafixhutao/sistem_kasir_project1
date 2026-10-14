<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class UsernameCompletionTest extends TestCase
{
    public function test_missing_username_and_null_username_are_rejected(): void
    {
        $this->postJson('/api/login', ['password' => bin2hex(random_bytes(16))])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/login', ['username' => 'missing_'.bin2hex(random_bytes(8)), 'password' => 'wrong'])
            ->assertUnauthorized();
        $user = User::factory()->create();
        $rejected = false;
        try { DB::table('users')->where('id', $user->id)->update(['username' => null]); }
        catch (\Illuminate\Database\QueryException $e) { $rejected = (int) ($e->errorInfo[1] ?? 0) === 1048; }
        $this->assertTrue($rejected, 'Username NOT NULL constraint must reject null.');
    }

    public function test_login_and_logout_preserve_fixture_identity_hash_and_other_token(): void
    {
        $plain = bin2hex(random_bytes(16));
        $user = User::factory()->create(['password' => Hash::make($plain)]);
        // Compare persisted values in database column order, not the newly
        // constructed model's attribute order or pre-save timestamp format.
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $other = User::factory()->create();
        $otherTokenId = $other->createToken('other-fixture')->accessToken->id;
        $login = $this->postJson('/api/login', ['username'=>$user->username,'password'=>$plain])->assertOk();
        $this->withToken($login->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('username', $user->username);
        $this->withToken($login->json('token'))->postJson('/api/logout')->assertOk();
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())), 'Fixture account must be unchanged.');
        $this->assertTrue($other->tokens()->whereKey($otherTokenId)->exists(), 'Another account token must survive logout.');
    }

    public function test_effective_schema_and_auth_source_use_username(): void
    {
        TestDatabaseGuard::assertActual(DB::connection());
        $column = DB::selectOne("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='username'");
        $this->assertSame('varchar(50)', $column->COLUMN_TYPE);
        $this->assertSame('NO', $column->IS_NULLABLE);
        if (DB::table('migrations')->where('migration', '2026_10_08_000001_remove_legacy_email_from_users')->exists()) {
            $this->assertFalse(Schema::hasColumn('users', 'email'));
            $this->assertFalse(Schema::hasColumn('users', 'email_verified_at'));
        }
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $source = file_get_contents(app_path('Http/Controllers/AuthController.php'));
        $this->assertStringContainsString("where('username'", $source);
        $this->assertDoesNotMatchRegularExpression('/where\s*\(\s*[\x27\x22]email[\x27\x22]/', $source);
        $login = file_get_contents(base_path('../frontend/src/pages/Login.jsx'));
        $hook = file_get_contents(base_path('../frontend/src/hooks/useAuth.js'));
        $this->assertStringContainsString('username', $login);
        $this->assertStringContainsString('username', $hook);
        $this->assertDoesNotMatchRegularExpression('/type\s*=\s*[\x27\x22]email[\x27\x22]/', $login);
    }

    public function test_url_and_cache_overrides_are_rejected_without_connecting(): void
    {
        foreach (['DB_URL','DATABASE_URL','APP_CONFIG_CACHE','APP_ROUTES_CACHE','APP_BASE_PATH'] as $key) {
            $old = getenv($key);
            try {
                putenv($key.'=unsafe-test-override');
                $rejected = false;
                try { TestDatabaseGuard::settings(base_path()); }
                catch (\RuntimeException $e) { $rejected = true; }
                $this->assertTrue($rejected, 'Override must be rejected before connection.');
            } finally { $old === false ? putenv($key) : putenv($key.'='.$old); }
        }
    }

    public function test_migration_preflight_rejects_empty_username_before_ddl(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['username' => '']);
        $before = array_values((array) DB::selectOne('SHOW CREATE TABLE users'))[1];
        $migration = require database_path('migrations/2026_10_08_000001_remove_legacy_email_from_users.php');
        $rejected = false;
        try { $migration->preflight(Schema::hasColumn('users', 'email')); }
        catch (\RuntimeException $e) { $rejected = true; }
        $this->assertTrue($rejected, 'Invalid data must fail preflight.');
        $this->assertSame($before, array_values((array) DB::selectOne('SHOW CREATE TABLE users'))[1]);
    }
}
