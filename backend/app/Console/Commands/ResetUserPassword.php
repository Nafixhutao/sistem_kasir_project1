<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordManagement;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password {username : Username persis akun target}';
    protected $description = 'Reset password secara lokal dengan input tersembunyi dan cabut token akun target.';

    public function handle(PasswordManagement $passwords): int
    {
        try {
            if (!$this->input->isInteractive()) {
                $this->error('Command membutuhkan input interaktif tersembunyi.');
                return self::FAILURE;
            }
            $user = User::whereRaw('BINARY username = ?', [(string) $this->argument('username')])->first();
            if (!$user) {
                $this->error('Username tidak ditemukan. Tidak ada akun yang diubah.');
                return self::FAILURE;
            }
            $password = $this->secret('New password', false);
            $confirmation = $this->secret('Confirm password', false);
            $validator = Validator::make(['password'=>$password, 'password_confirmation'=>$confirmation],
                ['password'=>PasswordPolicy::rules()], ['password.min'=>'Password minimal 8 karakter.', 'password.confirmed'=>'Konfirmasi password tidak cocok.']);
            if ($validator->fails()) {
                $this->error('Password tidak valid: minimal 8 karakter, maksimal 72 byte, tanpa NUL, dan konfirmasi harus cocok.');
                return self::FAILURE;
            }
            $passwords->replace($user->id, $password);
            unset($password, $confirmation);
            $this->info('Password diperbarui. Seluruh token akun target dicabut; silakan login kembali.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Never print/log exception arguments, password values or SQL bindings.
            $this->error('Reset dibatalkan atau gagal. Tidak ada perubahan parsial yang disimpan.');
            return self::FAILURE;
        }
    }
}
