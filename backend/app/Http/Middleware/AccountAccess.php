<?php
namespace App\Http\Middleware;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
class AccountAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $fresh = $user ? User::find($user->id) : null;
        if (!$fresh || !$fresh->is_active) return response()->json(['message'=>'Sesi tidak berlaku. Silakan login kembali.'],401);
        $role = $fresh->role;
        if (!in_array($role,['kasir','admin'],true)) abort(403);
        $path = $request->path();
        $management = preg_match('~^api/users(?:/[0-9]+/(?:status|reset-password))?$~D', $path);
        if ($management && $role !== 'admin') abort(403);
        $suppliers = preg_match('~^api/suppliers(?:/[0-9]+)?$~D', $path);
        if ($suppliers && $role !== 'admin' && !$request->isMethod('GET')) abort(403);
        $fees = preg_match('~^api/biaya-admin(?:/[^/]+)?$~D', $path);
        if ($fees) {
            $view = $request->isMethod('GET') && $path === 'api/biaya-admin';
            $manage = $role === 'admin' && $request->isMethod('PUT') && $path !== 'api/biaya-admin';
            if (!$view && !$manage) abort(403);
        }
        if ($role === 'admin' && !$management && !$fees && !$suppliers) {
            $read = $request->isMethod('GET') && preg_match('~^api/(?:me|dashboard|barang(?:/[0-9]+)?|barang-search|laporan/(?:penjualan|retur-pelanggan)|riwayat-brilink|pengeluaran-toko|retur-supplier(?:/[0-9]+)?|activity-logs|notifikasi(?:/unread-count)?)$~D',$path);
            $own = ($request->isMethod('POST') && in_array($path,['api/logout','api/change-password'],true))
                || ($request->isMethod('PATCH') && preg_match('~^api/notifikasi/(?:[0-9]+/read|read-all)$~D',$path));
            if (!$read && !$own) abort(403);
        }
        // Keep the Sanctum token on the original instance while using current DB role/status.
        $user->role = $fresh->role;
        $user->is_active = $fresh->is_active;
        return $next($request);
    }
}
