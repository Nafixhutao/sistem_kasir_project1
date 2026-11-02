<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimulasiPembayaranController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/**
 * Mengirim index.html aplikasi kasir.
 *
 * HTML sengaja dikirim dengan no-store supaya browser selalu mengambil versi
 * terbaru. Nama berkas aset (index-xxxx.js) sudah ber-hash, jadi cache aset
 * tetap aman; yang tidak boleh di-cache hanya HTML-nya. Tanpa ini, setelah
 * deploy pengguna bisa tetap melihat tampilan lama sampai cache kedaluwarsa.
 */
$kirimAplikasi = function () {
    $frontend = public_path('app/index.html');

    if (!file_exists($frontend)) {
        return null;
    }

    return response()->file($frontend, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
    ]);
};

Route::get('/', function () use ($kirimAplikasi) {
    return $kirimAplikasi() ?? view('welcome');
});

// Halaman simulasi pembayaran (demo sandbox). Harus didaftarkan sebelum rute
// penangkap SPA di bawah, supaya tidak ikut diarahkan ke aplikasi kasir.
Route::get('/bayar-simulasi/{orderId}', [SimulasiPembayaranController::class, 'show'])
    ->name('simulasi.bayar');
Route::post('/bayar-simulasi/{orderId}', [SimulasiPembayaranController::class, 'bayar'])
    ->name('simulasi.bayar.proses');

Route::get('/{any}', function () use ($kirimAplikasi) {
    $response = $kirimAplikasi();

    if (!$response) {
        abort(404, 'Frontend belum dibuild. Jalankan npm run build di folder frontend.');
    }

    return $response;
})->where('any', '^(?!api).*$');
