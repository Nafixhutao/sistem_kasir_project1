<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    public function me(Request $request)
    {
        return response()->json($request->user()->only(['id','name','username','role']));
    }
}
