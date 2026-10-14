<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    public function test_username_login_me_logout_without_public_reset(): void
    {
        $plain = bin2hex(random_bytes(16));
        $user = User::factory()->create(['password' => Hash::make($plain)]);
        if (Schema::hasColumn('users', 'email')) {
            DB::table('users')->where('id', $user->id)->update(['email' => 'legacy_'.$user->id]);
            $this->postJson('/api/login', ['username'=>'legacy_'.$user->id,'password'=>$plain])->assertUnauthorized();
        }
        $this->assertTrue(Hash::check($plain, $user->getRawOriginal('password')));
        $this->postJson('/api/login', ['username'=>$user->username,'password'=>'wrong'])->assertUnauthorized();
        $login = $this->postJson('/api/login', ['username'=>$user->username,'password'=>$plain])->assertOk();
        $this->assertSame($user->username, $login->json('user.username'));
        foreach (['password','remember_token','tokens','email','email_verified_at'] as $key) {
            $this->assertArrayNotHasKey($key, $login->json('user'));
        }
        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('username', $user->username);
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $next = bin2hex(random_bytes(16));
        $this->postJson('/api/reset-password', ['username'=>$user->username,'password'=>$next,'password_confirmation'=>$next])->assertNotFound();
        $this->assertTrue(Hash::check($plain, $user->fresh()->getRawOriginal('password')));
    }

    public function test_username_validation_and_unique_index(): void
    {
        foreach (['/api/login'] as $endpoint) {
            foreach (['', str_repeat('u',51)] as $username) {
                $this->postJson($endpoint,['username'=>$username,'password'=>'fixture-only','password_confirmation'=>'fixture-only'])
                    ->assertUnprocessable()->assertJsonValidationErrors('username');
            }
        }
        $plain = bin2hex(random_bytes(16));
        $user = User::factory()->create(['username'=>str_repeat('u',50),'password'=>Hash::make($plain)]);
        $this->postJson('/api/login',['username'=>$user->username,'password'=>$plain])->assertOk();
        $other = User::factory()->create();
        $rejected = false;
        try { DB::table('users')->where('id',$other->id)->update(['username'=>$user->username]); }
        catch (\Illuminate\Database\QueryException $e) { $rejected = $e->errorInfo[1] === 1062; }
        $this->assertTrue($rejected, 'Database must reject duplicate username.');
        $this->assertSame('sistem_project1_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_backup_allowlist_excludes_accounts_without_running_restore(): void
    {
        $tables = \App\Support\BusinessBackup::TABLES;
        foreach (['users','password_reset_tokens','personal_access_tokens'] as $table) {
            $this->assertNotContains($table, $tables);
        }
    }

    public function test_guard_rejects_primary_database_before_boot(): void
    {
        $old = getenv('DB_DATABASE');
        $rejected = false;
        try {
            putenv('DB_DATABASE=sistem_project1');
            $guard = new \ReflectionMethod(\Tests\TestCase::class, 'guardConfiguredDatabaseBeforeLaravelBoots');
            $guard->setAccessible(true);
            try { $guard->invoke($this); }
            catch (\RuntimeException $e) { $rejected = true; }
        } finally {
            $old === false ? putenv('DB_DATABASE') : putenv('DB_DATABASE='.$old);
        }
        $this->assertTrue($rejected, 'Primary database configuration must be rejected.');
    }
}
