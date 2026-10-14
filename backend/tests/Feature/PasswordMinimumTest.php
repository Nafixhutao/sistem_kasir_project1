<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordMinimumTest extends TestCase
{
    private function asToken(?string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        if ($token) $this->withToken($token);
        return $this;
    }

    public function test_policy_accepts_eight_characters_but_keeps_other_guards(): void
    {
        foreach ([7 => false, 8 => true, 12 => true, 72 => true, 73 => false] as $length => $expected) {
            $password = str_repeat('p', $length);
            $this->assertSame($expected, Validator::make(
                ['password' => $password, 'password_confirmation' => $password],
                ['password' => PasswordPolicy::rules()]
            )->passes());
        }
        foreach ([str_repeat('p', 8)."\0", str_repeat('é', 37)] as $password) {
            $this->assertTrue(Validator::make(
                ['password' => $password, 'password_confirmation' => $password],
                ['password' => PasswordPolicy::rules()]
            )->fails());
        }
        $this->assertTrue(Validator::make(
            ['password' => str_repeat('p', 8), 'password_confirmation' => str_repeat('q', 8)],
            ['password' => PasswordPolicy::rules()]
        )->fails());
    }

    public function test_hidden_commands_accept_eight_and_reject_seven_characters(): void
    {
        $username = 'minimum_'.Str::lower(Str::random(12));
        $password = bin2hex(random_bytes(4));
        $next = bin2hex(random_bytes(4));
        $count = User::count();
        $this->artisan('user:create-owner')
            ->expectsQuestion('Name', 'Rejected boundary fixture')
            ->expectsQuestion('Username', $username)
            ->expectsQuestion('New password', str_repeat('p', 7))
            ->expectsQuestion('Confirm password', str_repeat('p', 7))
            ->expectsOutput('Input tidak valid atau username sudah digunakan.')->assertExitCode(1);
        $this->assertSame($count, User::count());
        $this->artisan('user:create-owner')
            ->expectsQuestion('Name', 'Password boundary fixture')
            ->expectsQuestion('Username', $username)
            ->expectsQuestion('New password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput('Akun Admin berhasil dibuat.')->assertExitCode(0);
        $user = User::where('username', $username)->firstOrFail();
        $this->assertSame($count + 1, User::count());
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check($password, $user->password));
        $before = $user->password;
        $this->artisan('user:reset-password', ['username' => $username])
            ->expectsQuestion('New password', str_repeat('p', 7))
            ->expectsQuestion('Confirm password', str_repeat('p', 7))
            ->expectsOutput('Password tidak valid: minimal 8 karakter, maksimal 72 byte, tanpa NUL, dan konfirmasi harus cocok.')->assertExitCode(1);
        $this->assertTrue($before === $user->fresh()->password);
        $user->createToken('minimum-fixture');
        $this->artisan('user:reset-password', ['username' => $username])
            ->expectsQuestion('New password', $next)
            ->expectsQuestion('Confirm password', $next)
            ->expectsOutput('Password diperbarui. Seluruh token akun target dicabut; silakan login kembali.')->assertExitCode(0);
        $this->assertTrue(Hash::check($next, $user->fresh()->password));
        $this->assertSame($username, $user->fresh()->username);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_cashier_create_self_change_and_admin_reset_accept_eight_characters(): void
    {
        $adminPassword = bin2hex(random_bytes(4));
        $admin = User::factory()->create(['password' => Hash::make($adminPassword)]);
        $admin->role = 'admin'; $admin->save();
        $adminToken = $admin->createToken('minimum-admin')->plainTextToken;
        $password = bin2hex(random_bytes(4));
        $next = bin2hex(random_bytes(4));
        $reset = bin2hex(random_bytes(4));
        $data = ['name' => 'Minimum fixture', 'username' => 'min_cashier_'.Str::lower(Str::random(12)),
            'password' => $password, 'password_confirmation' => $password];
        $this->asToken($adminToken)->postJson('/api/users', array_replace($data,
            ['password' => str_repeat('p', 7), 'password_confirmation' => str_repeat('p', 7)]))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $created = $this->asToken($adminToken)->postJson('/api/users', $data)
            ->assertCreated()->assertJsonPath('role', 'kasir');
        $cashier = User::findOrFail($created->json('id'));
        $this->assertTrue(Hash::check($password, $cashier->password));
        $cashierToken = $cashier->createToken('minimum-cashier')->plainTextToken;
        $change = ['current_password' => $password, 'password' => $next, 'password_confirmation' => $next];
        $this->asToken($cashierToken)->postJson('/api/change-password', array_replace($change,
            ['password' => str_repeat('p', 7), 'password_confirmation' => str_repeat('p', 7)]))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asToken($cashierToken)->postJson('/api/change-password', $change)->assertOk();
        $this->assertTrue(Hash::check($next, $cashier->fresh()->password));
        $this->assertSame(0, $cashier->tokens()->count());
        $resetData = ['current_password' => $adminPassword, 'password' => $reset, 'password_confirmation' => $reset];
        $this->asToken($adminToken)->postJson('/api/users/'.$cashier->id.'/reset-password', array_replace($resetData,
            ['password' => str_repeat('p', 7), 'password_confirmation' => str_repeat('p', 7)]))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->asToken($adminToken)->postJson('/api/users/'.$cashier->id.'/reset-password', $resetData)->assertOk();
        $this->assertTrue(Hash::check($reset, $cashier->fresh()->password));
        $this->assertTrue(Hash::check($adminPassword, $admin->fresh()->password));
        $this->assertSame(1, $admin->tokens()->count());
        $this->asToken(null)->postJson('/api/login', ['username' => $cashier->username, 'password' => $reset])->assertOk();
    }
}
