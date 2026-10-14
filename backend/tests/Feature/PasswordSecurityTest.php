<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordSecurityTest extends TestCase
{
    private function fixture(): array
    {
        $plain = bin2hex(random_bytes(16));
        $user = User::factory()->create(['password'=>Hash::make($plain)]);
        $tokens = [$user->createToken('fixture-one')->plainTextToken, $user->createToken('fixture-two')->plainTextToken];
        return [$user, $plain, $tokens];
    }

    private function identity(User $user): string
    {
        return hash('sha256', json_encode($user->fresh()->only(['id','name','username','remember_token','created_at'])));
    }

    public function test_public_reset_and_anonymous_change_are_blocked(): void
    {
        [$user, $plain, $tokens] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $next = bin2hex(random_bytes(16));
        $this->postJson('/api/reset-password', ['username'=>$user->username,'password'=>$next,'password_confirmation'=>$next])->assertNotFound();
        $this->postJson('/api/change-password', ['current_password'=>$plain,'password'=>$next,'password_confirmation'=>$next])->assertUnauthorized();
        $this->withToken($tokens[0])->postJson('/api/reset-password', ['username'=>$user->username,'password'=>$next,'password_confirmation'=>$next])->assertNotFound();
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_change_password_revokes_only_target_tokens_and_preserves_identity(): void
    {
        [$user, $plain, $tokens] = $this->fixture();
        [$other, $otherPlain, $otherTokens] = $this->fixture();
        $before = $this->identity($user);
        $otherBefore = hash('sha256', json_encode($other->fresh()->getRawOriginal()));
        $next = bin2hex(random_bytes(16));
        $response = $this->withToken($tokens[0])->postJson('/api/change-password', ['current_password'=>$plain,'password'=>$next,'password_confirmation'=>$next])->assertOk();
        $this->assertSame(['message'], array_keys($response->json()));
        $this->assertTrue(Hash::check($next, $user->fresh()->getRawOriginal('password')));
        $this->assertTrue($next !== $user->fresh()->getRawOriginal('password'));
        $this->assertTrue($before === $this->identity($user));
        $this->assertTrue($otherBefore === hash('sha256', json_encode($other->fresh()->getRawOriginal())));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(2, $other->tokens()->count());
        foreach ($tokens as $token) {
            \Illuminate\Support\Facades\Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        }
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($otherTokens[0])->getJson('/api/me')->assertOk();
        $this->postJson('/api/login', ['username'=>$user->username,'password'=>$plain])->assertUnauthorized();
        $this->postJson('/api/login', ['username'=>$user->username,'password'=>$next])->assertOk();
        $log = ActivityLog::where('action','change-password')->latest('id')->firstOrFail();
        $this->assertSame($user->username, $log->user_name);
        $this->assertSame(['user_id'=>$user->id,'username'=>$user->username], $log->properties);
        $this->assertFalse(str_contains(json_encode($log), $next));
        $this->assertFalse(str_contains(json_encode($log), $user->fresh()->getRawOriginal('password')));
    }

    public function test_invalid_change_requests_never_modify_account_or_tokens(): void
    {
        [$user, $plain, $tokens] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $valid = ['current_password'=>$plain,'password'=>str_repeat('n',8),'password_confirmation'=>str_repeat('n',8)];
        $cases = [
            array_replace($valid, ['current_password'=>'wrong']),
            array_replace($valid, ['password_confirmation'=>'mismatch']),
            array_replace($valid, ['password'=>str_repeat('n',7),'password_confirmation'=>str_repeat('n',7)]),
            array_replace($valid, ['password'=>str_repeat('n',73),'password_confirmation'=>str_repeat('n',73)]),
            array_replace($valid, ['password'=>str_repeat('é',37),'password_confirmation'=>str_repeat('é',37)]),
            array_replace($valid, ['password'=>"abcdefghijkl\0",'password_confirmation'=>"abcdefghijkl\0"]),
            array_replace($valid, ['password'=>$plain,'password_confirmation'=>$plain]),
            $valid+['username'=>'admin'], $valid+['role'=>'admin'], $valid+['user_id'=>1],
        ];
        foreach ($cases as $payload) $this->withToken($tokens[0])->postJson('/api/change-password', $payload)->assertUnprocessable();
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_password_policy_accepts_72_bytes_and_passphrase_without_symbols(): void
    {
        [$user, $plain, $tokens] = $this->fixture();
        $next = str_repeat('a',72);
        $this->withToken($tokens[0])->postJson('/api/change-password', ['current_password'=>$plain,'password'=>$next,'password_confirmation'=>$next])->assertOk();
        $this->assertTrue(Hash::check($next, $user->fresh()->getRawOriginal('password')));
    }

    public function test_user_responses_are_exact_allowlists(): void
    {
        [$user, $plain] = $this->fixture();
        $login = $this->postJson('/api/login', ['username'=>$user->username,'password'=>$plain])->assertOk();
        $this->assertSame(['id','name','username','role'], array_keys($login->json('user')));
        $this->assertSame(['message','user','token'], array_keys($login->json()));
        $me = $this->withToken($login->json('token'))->getJson('/api/me')->assertOk();
        $this->assertSame(['id','name','username','role'], array_keys($me->json()));
    }

    public function test_local_reset_preserves_other_user_and_uses_hidden_input(): void
    {
        [$user, $plain] = $this->fixture();
        [$other] = $this->fixture();
        $before = $this->identity($user);
        $otherBefore = hash('sha256', json_encode($other->fresh()->getRawOriginal()));
        $next = bin2hex(random_bytes(16));
        $this->artisan('user:reset-password', ['username'=>$user->username])
            ->expectsQuestion('New password', $next)->expectsQuestion('Confirm password', $next)
            ->expectsOutput('Password diperbarui. Seluruh token akun target dicabut; silakan login kembali.')
            ->assertExitCode(0);
        $this->assertTrue($before === $this->identity($user));
        $this->assertTrue(Hash::check($next, $user->fresh()->getRawOriginal('password')));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(2, $other->tokens()->count());
        $this->assertTrue($otherBefore === hash('sha256', json_encode($other->fresh()->getRawOriginal())));
        $this->postJson('/api/login', ['username'=>$user->username,'password'=>$plain])->assertUnauthorized();
        $this->postJson('/api/login', ['username'=>$user->username,'password'=>$next])->assertOk();
        $source = file_get_contents(app_path('Console/Commands/ResetUserPassword.php'));
        $this->assertStringContainsString("secret('New password', false)", $source);
        $this->assertStringContainsString("secret('Confirm password', false)", $source);
        $log = ActivityLog::where('action','local-reset-password')->latest('id')->firstOrFail();
        $this->assertFalse(str_contains(json_encode($log), $next));
        $this->assertSame(['user_id'=>$user->id,'username'=>$user->username], $log->properties);
    }

    public function test_local_reset_unknown_and_case_mismatch_do_not_create_users(): void
    {
        [$user] = $this->fixture();
        $count = User::count();
        foreach (['missing_'.bin2hex(random_bytes(8)), strtoupper($user->username)] as $username) {
            $this->artisan('user:reset-password', ['username'=>$username])
                ->expectsOutput('Username tidak ditemukan. Tidak ada akun yang diubah.')->assertExitCode(1);
        }
        $this->assertSame($count, User::count());
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_local_reset_invalid_confirmation_does_not_save(): void
    {
        [$user] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $this->artisan('user:reset-password', ['username'=>$user->username])
            ->expectsQuestion('New password', str_repeat('a',12))->expectsQuestion('Confirm password', str_repeat('b',12))
            ->expectsOutput('Password tidak valid: minimal 8 karakter, maksimal 72 byte, tanpa NUL, dan konfirmasi harus cocok.')->assertExitCode(1);
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_local_command_signature_has_no_password_argument_and_requires_interaction(): void
    {
        $command = Artisan::all()['user:reset-password'];
        $arguments = array_keys($command->getDefinition()->getArguments());
        $this->assertSame(['username'], $arguments);
        $this->artisan('user:reset-password', ['username'=>'unused', '--no-interaction'=>true])
            ->expectsOutput('Command membutuhkan input interaktif tersembunyi.')->assertExitCode(1);
    }

    public function test_local_command_rejects_extra_argument_before_account_changes(): void
    {
        [$user] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $rejected = false;
        try { Artisan::call('user:reset-password '.$user->username.' forbidden-extra-argument'); }
        catch (\Symfony\Component\Console\Exception\RuntimeException $e) { $rejected = true; }
        $this->assertTrue($rejected, 'Additional password argument must not be accepted.');
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_local_reset_rejects_weak_password_and_cancelled_input(): void
    {
        [$user] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        foreach ([str_repeat('a',7), null] as $password) {
            $this->artisan('user:reset-password', ['username'=>$user->username])
                ->expectsQuestion('New password', $password)->expectsQuestion('Confirm password', $password)
                ->expectsOutput('Password tidak valid: minimal 8 karakter, maksimal 72 byte, tanpa NUL, dan konfirmasi harus cocok.')->assertExitCode(1);
        }
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_transaction_failure_does_not_leave_password_or_token_changes(): void
    {
        [$user, $plain, $tokens] = $this->fixture();
        $before = hash('sha256', json_encode($user->fresh()->getRawOriginal()));
        $event = 'eloquent.creating: '.ActivityLog::class;
        \Illuminate\Support\Facades\Event::listen($event, function (): void { throw new \RuntimeException('Controlled fixture failure.'); });
        try {
            $next = bin2hex(random_bytes(16));
            $this->withToken($tokens[0])->postJson('/api/change-password', ['current_password'=>$plain,'password'=>$next,'password_confirmation'=>$next])->assertStatus(503);
        } finally { \Illuminate\Support\Facades\Event::forget($event); }
        $this->assertTrue($before === hash('sha256', json_encode($user->fresh()->getRawOriginal())));
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_login_limits_username_ip_and_persists_across_application_bootstraps(): void
    {
        $payload = ['username'=>'limit_'.bin2hex(random_bytes(8)), 'password'=>'wrong'];
        for ($i=0; $i<5; $i++) $this->postJson('/api/login', $payload)->assertUnauthorized();
        $oldApp = $this->app;
        try {
            $newApp = $this->createApplication();
            $limiter = $newApp->make(\Illuminate\Cache\RateLimiter::class);
            $routeLimiter = $limiter->limiter('login');
            $request = \Illuminate\Http\Request::create('/api/login','POST',$payload,[],[],['REMOTE_ADDR'=>$this->serverVariables['REMOTE_ADDR']]);
            $limit = $routeLimiter($request)[0];
            $this->assertTrue($limiter->attempts(md5('login'.$limit->key)) >= 5, 'File limiter must persist between applications.');
        } finally {
            \Illuminate\Container\Container::setInstance($oldApp);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($oldApp);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
        $this->postJson('/api/login', $payload)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_login_ip_limit_blocks_rotation_of_usernames(): void
    {
        for ($i=0; $i<30; $i++) $this->postJson('/api/login', ['username'=>'rotating_'.$i, 'password'=>'wrong'])->assertUnauthorized();
        $this->postJson('/api/login', ['username'=>'rotating_last','password'=>'wrong'])->assertStatus(429)->assertHeader('Retry-After');
    }
}
