<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\PenjualanController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TarikTunaiController;
use App\Http\Controllers\SetorTunaiController;
use App\Http\Controllers\TagihanController;
use App\Http\Controllers\PulsaController;
use App\Http\Controllers\BrilinkController;
use App\Http\Controllers\BiayaAdminController;
use App\Http\Controllers\EwalletController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\PengeluaranTokoController;
use App\Http\Controllers\ReturSupplierController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\NotifikasiController;

foreach (['id', 'barang', 'supplier', 'transfer', 'tarik_tunai', 'setor_tunai', 'tagihan', 'pulsa', 'ewallet', 'pengeluaran_toko', 'retur_supplier'] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}

// Public routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/lupa-password', [AuthController::class, 'lupaPassword'])->middleware('throttle:login');
Route::post('/midtrans/notification', [PenjualanController::class, 'qrisWebhook']);
Route::post('/sumopod/webhook', [PenjualanController::class, 'sumopodWebhook']);

// Protected routes
Route::middleware(['auth:sanctum', \App\Http\Middleware\AccountAccess::class])->group(function () {
    Route::get('/users', [\App\Http\Controllers\UserManagementController::class, 'index']);
    Route::post('/users', [\App\Http\Controllers\UserManagementController::class, 'store']);
    Route::patch('/users/{id}/status', [\App\Http\Controllers\UserManagementController::class, 'status']);
    Route::post('/users/{id}/reset-password', [\App\Http\Controllers\UserManagementController::class, 'resetPassword']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Barang
    Route::apiResource('suppliers', \App\Http\Controllers\SupplierController::class);
    Route::apiResource('barang', BarangController::class);
    Route::post('/barang/import', [BarangController::class, 'import']);
    Route::get('/barang-search', [BarangController::class, 'search']);

    // Penjualan
    Route::get('/penjualan', [PenjualanController::class, 'index']);
    Route::post('/penjualan', [PenjualanController::class, 'store']);
    Route::post('/penjualan/qris', [PenjualanController::class, 'qris']);
    Route::post('/penjualan/qris/status', [PenjualanController::class, 'qrisStatus']);
    Route::post('/penjualan/qris/manual-confirm', [PenjualanController::class, 'qrisManualConfirm']);
    Route::post('/penjualan/{id}/cancel', [PenjualanController::class, 'cancel']);
    Route::post('/penjualan/{id}/retur', [PenjualanController::class, 'retur']);
    Route::get('/penjualan/invoice/{id}', [PenjualanController::class, 'invoice']);

    // Laporan
    Route::get('/laporan/penjualan', [LaporanController::class, 'penjualan']);
    Route::get('/laporan/retur-pelanggan', [LaporanController::class, 'returPelanggan']);
    Route::delete('/laporan/penjualan/reset', [LaporanController::class, 'resetPenjualan']);

    // BRILink
    Route::get('/providers', [ProviderController::class, 'index']);
    Route::apiResource('transfer', TransferController::class);
    Route::apiResource('tarik-tunai', TarikTunaiController::class);
    Route::apiResource('setor-tunai', SetorTunaiController::class);
    Route::apiResource('tagihan', TagihanController::class);
    Route::apiResource('pulsa', PulsaController::class);
    Route::apiResource('ewallet', EwalletController::class);

    // Riwayat BRILink
    Route::get('/riwayat-brilink', [BrilinkController::class, 'riwayat']);

    // Stok, backup, aktivitas
    Route::get('/backup-data', [BackupController::class, 'download']);
    Route::post('/backup-data/restore', [BackupController::class, 'restore']);
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/notifikasi', [NotifikasiController::class, 'index']);
    Route::get('/notifikasi/unread-count', [NotifikasiController::class, 'count']);
    Route::patch('/notifikasi/{id}/read', [NotifikasiController::class, 'markAsRead']);
    Route::patch('/notifikasi/read-all', [NotifikasiController::class, 'markAllAsRead']);
    Route::apiResource('pengeluaran-toko', PengeluaranTokoController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('retur-supplier', ReturSupplierController::class);

    // Biaya Admin
    Route::get('/biaya-admin', [BiayaAdminController::class, 'index']);
    Route::put('/biaya-admin/{jenisTransaksi}', [BiayaAdminController::class, 'update']);
});
