<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PasswordManagement
{
    public function replace(int $id, string $password, ?string $currentPassword = null): void
    {
        DB::transaction(function () use ($id, $password, $currentPassword): void {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($currentPassword !== null && !Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password lama salah.']);
            }
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password lama.']);
            }
            $user->password = Hash::make($password);
            $user->save();
            $user->tokens()->delete();
            ActivityLog::create([
                'user_name' => $currentPassword === null ? 'local-operator' : $user->username,
                'module' => 'Auth',
                'action' => $currentPassword === null ? 'local-reset-password' : 'change-password',
                'description' => 'Password akun diperbarui dan seluruh token akun dicabut.',
                'properties' => ['user_id' => $user->id, 'username' => $user->username],
            ]);
        });
    }
}
