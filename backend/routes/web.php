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

Route::get('/', function () {
    $frontend = public_path('app/index.html');

    if (file_exists($frontend)) {
        return response()->file($frontend);
    }

    return view('welcome');
});

// Halaman simulasi pembayaran (demo sandbox). Harus didaftarkan sebelum rute
// penangkap SPA di bawah, supaya tidak ikut diarahkan ke aplikasi kasir.
Route::get('/bayar-simulasi/{orderId}', [SimulasiPembayaranController::class, 'show'])
    ->name('simulasi.bayar');
Route::post('/bayar-simulasi/{orderId}', [SimulasiPembayaranController::class, 'bayar'])
    ->name('simulasi.bayar.proses');

Route::get('/{any}', function () {
    $frontend = public_path('app/index.html');

    if (!file_exists($frontend)) {
        abort(404, 'Frontend belum dibuild. Jalankan npm run build di folder frontend.');
    }

    return response()->file($frontend);
})->where('any', '^(?!api).*$');
