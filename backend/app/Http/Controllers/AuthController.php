<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\PasswordManagement;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string',
        ]);

        return DB::transaction(function () use ($data) {
            $user = User::where('username', $data['username'])->lockForUpdate()->first();
            if (!$user || !$user->is_active || !Hash::check($data['password'], $user->password)) {
                return response()->json(['message' => 'Username atau password salah'], 401);
            }
            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json(['message'=>'Login berhasil', 'user'=>$user->only(['id','name','username','role']), 'token'=>$token]);
        });
    }

    public function changePassword(Request $request, PasswordManagement $passwords)
    {
        if (array_diff(array_keys($request->all()), ['current_password','password','password_confirmation'])) {
            throw ValidationException::withMessages(['request' => 'Hanya field password akun sendiri yang diperbolehkan.']);
        }
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => PasswordPolicy::rules(),
            'password_confirmation' => 'required|string',
        ]);
        try {
            $passwords->replace($request->user()->id, $data['password'], $data['current_password']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Perubahan password gagal. Silakan coba kembali.'], 503);
        }
        return response()->json(['message' => 'Password berhasil diubah. Silakan login kembali.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }

    /**
     * Reset mandiri lewat halaman login: pengguna lupa password membuat password baru,
     * diotorisasi oleh password admin (pemilik toko). Tidak perlu login.
     */
    public function lupaPassword(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'password' => PasswordPolicy::rules(),
            'password_confirmation' => 'required|string',
            'admin_password' => 'required|string',
        ], [
            'admin_password.required' => 'Password admin wajib diisi sebagai otorisasi reset.',
        ]);

        $target = User::where('username', $data['username'])->first();
        if (!$target || !$target->is_active) {
            throw ValidationException::withMessages(['username' => 'Akun tidak ditemukan atau tidak aktif.']);
        }

        $admin = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
        if (!$admin || !Hash::check($data['admin_password'], $admin->password)) {
            throw ValidationException::withMessages(['admin_password' => 'Password admin tidak cocok. Reset password dibatalkan.']);
        }

        if (Hash::check($data['password'], $target->password)) {
            throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari yang lama.']);
        }

        $target->password = Hash::make($data['password']);
        $target->save();
        $target->tokens()->delete();

        ActivityLog::record('Auth', 'lupa-password', "Reset password akun {$target->username} dengan otorisasi admin");

        return response()->json(['message' => 'Password berhasil dibuat ulang. Silakan login dengan password baru.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->only(['id','name','username','role']));
    }
}
