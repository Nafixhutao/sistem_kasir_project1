<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    public function test_brilink_expense_fee_supplier_return_activity_and_backup_workflows(): void
    {
        $cashier = User::factory()->create();
        Sanctum::actingAs($cashier);

        $this->postJson('/api/transfer', [
            'provider' => 'BRILink Mobile',
            'jenis_transfer' => 'Antar Bank',
            'bank_tujuan' => 'BCA',
            'nomor_rekening_tujuan' => '000123456789',
            'nama_penerima' => 'Penerima Test',
            'jenis_nasabah' => 'internal',
            'nominal_transfer' => 100000,
        ])->assertCreated()->assertJsonPath('nomor_rekening_tujuan', '000123456789');

        $this->postJson('/api/tarik-tunai', [
            'provider' => 'BRILink Mobile',
            'nomor_rekening' => '000987654321',
            'nama_penerima' => 'Penerima Test',
            'nomor_hp' => '081234567890',
            'jenis_nasabah' => 'internal',
            'nominal_tarik' => 100000,
        ])->assertCreated()->assertJsonPath('nomor_rekening', '000987654321');

        $this->postJson('/api/setor-tunai', [
            'provider' => 'BRILink Mobile',
            'jenis_setoran' => 'biasa',
            'nomor_rekening_tujuan' => '000111222333',
            'nama_pemilik_rekening' => 'Pemilik Test',
            'bank_tujuan' => 'BRI',
            'jenis_nasabah' => 'internal',
            'nominal_setor' => 100000,
            'sumber_dana' => 'Uang Tunai',
        ])->assertCreated()->assertJsonPath('nomor_rekening_tujuan', '000111222333');

        $this->postJson('/api/tagihan', [
            'provider' => 'BRILink Mobile',
            'jenis_layanan' => 'pln',
            'nomor_pelanggan' => '000444555666',
            'nama_pelanggan' => 'Pelanggan Test',
            'jenis_nasabah' => 'internal',
            'jumlah_tagihan' => 100000,
        ])->assertCreated()->assertJsonPath('nomor_pelanggan', '000444555666');

        $this->postJson('/api/pulsa', [
            'provider' => 'BRILink Mobile',
            'operator' => 'Telkomsel',
            'jenis_layanan' => 'pulsa',
            'nomor_tujuan' => '081200001234',
            'produk' => 'Pulsa 50rb',
            'jenis_nasabah' => 'internal',
            'harga' => 50000,
        ])->assertCreated()->assertJsonPath('nomor_tujuan', '081200001234');

        $this->postJson('/api/ewallet', [
            'jenis_transaksi' => 'top_up',
            'provider' => 'BRILink Mobile',
            'jenis_ewallet' => 'DANA',
            'nomor_ewallet' => '081200009999',
            'nama_customer' => 'Customer Test',
            'nominal' => 50000,
        ])->assertCreated()->assertJsonPath('nomor_ewallet', '081200009999');

        $this->postJson('/api/pengeluaran-toko', [
            'tanggal' => now()->toDateString(),
            'kategori' => 'Operasional',
            'nama_pengeluaran' => 'Pengeluaran Pengujian',
            'nominal' => 25000,
        ])->assertCreated()->assertJsonPath('nama_pengeluaran', 'Pengeluaran Pengujian');

        $this->getJson('/api/biaya-admin')->assertOk()->assertJsonStructure(['layanan', 'data']);
        $admin = User::factory()->create(); $admin->role = 'admin'; $admin->save();
        Sanctum::actingAs($admin);
        $this->putJson('/api/biaya-admin/ewallet', [
            'ranges' => [[
                'nominal_min' => 1,
                'nominal_max' => null,
                'biaya_admin' => 2500,
                'aktif' => true,
            ]],
        ])->assertOk()->assertJsonPath('0.jenis_transaksi', 'ewallet');
        Sanctum::actingAs($cashier);

        $barang = Barang::create([
            'kode_barang' => '000SUP001',
            'nama_barang' => 'Barang Retur Supplier Test',
            'kategori' => 'Aksesoris',
            'stok' => 10,
            'harga_beli' => 10000,
            'harga_jual' => 15000,
        ]);
        $retur = $this->postJson('/api/retur-supplier', [
            'tanggal_retur' => now()->toDateString(),
            'nama_supplier' => 'Supplier Pengujian',
            'barang_id' => $barang->id,
            'jumlah_retur' => 2,
            'alasan_retur' => 'Barang rusak saat diterima',
        ])->assertCreated()->assertJsonPath('kode_barang', '000SUP001');
        $returId = $retur->json('id');

        $this->putJson('/api/retur-supplier/'.$returId, [
            'tanggal_retur' => now()->toDateString(),
            'nama_supplier' => 'Supplier Pengujian',
            'barang_id' => $barang->id,
            'jumlah_retur' => 2,
            'alasan_retur' => 'Barang rusak saat diterima',
            'status_retur' => 'diterima',
        ])->assertOk()->assertJsonPath('status_retur', 'diterima');
        $this->assertSame(8, (int) $barang->fresh()->stok);
        $this->deleteJson('/api/retur-supplier/'.$returId)->assertOk();
        $this->assertSame(10, (int) $barang->fresh()->stok);

        $this->getJson('/api/riwayat-brilink')->assertOk()
            ->assertJsonFragment(['nomor_rekening_tujuan' => '000123456789']);
        $this->getJson('/api/activity-logs')->assertOk();
        $this->getJson('/api/backup-data')->assertOk()
            ->assertJsonStructure(['app', 'backup_at', 'tables' => ['barang', 'transaksi_penjualan', 'activity_logs']]);
    }

    public function test_overlong_business_inputs_are_rejected_before_database_writes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/transfer', [
            'provider' => 'BRILink Mobile', 'jenis_transfer' => 'Antar Bank',
            'bank_tujuan' => 'BCA', 'nomor_rekening_tujuan' => str_repeat('1', 31),
            'nama_penerima' => 'Penerima', 'jenis_nasabah' => 'internal', 'nominal_transfer' => 100000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nomor_rekening_tujuan');

        $this->postJson('/api/tarik-tunai', [
            'provider' => 'BRILink Mobile', 'nomor_rekening' => '00123456789',
            'nama_penerima' => 'Penerima', 'nomor_hp' => str_repeat('1', 21),
            'jenis_nasabah' => 'internal', 'nominal_tarik' => 100000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nomor_hp');

        $this->postJson('/api/setor-tunai', [
            'provider' => 'BRILink Mobile', 'jenis_setoran' => 'biasa',
            'nomor_rekening_tujuan' => '00123456789', 'nama_pemilik_rekening' => 'Pemilik',
            'bank_tujuan' => str_repeat('B', 101), 'jenis_nasabah' => 'internal',
            'nominal_setor' => 100000, 'sumber_dana' => 'Tunai',
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_tujuan');

        $this->postJson('/api/tagihan', [
            'provider' => 'BRILink Mobile', 'jenis_layanan' => 'pln',
            'nomor_pelanggan' => str_repeat('1', 51), 'nama_pelanggan' => 'Pelanggan',
            'jenis_nasabah' => 'internal', 'jumlah_tagihan' => 100000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nomor_pelanggan');

        $this->postJson('/api/pulsa', [
            'provider' => 'BRILink Mobile', 'operator' => 'Telkomsel', 'jenis_layanan' => 'pulsa',
            'nomor_tujuan' => str_repeat('1', 21), 'produk' => 'Pulsa',
            'jenis_nasabah' => 'internal', 'harga' => 50000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nomor_tujuan');

        $this->postJson('/api/ewallet', [
            'jenis_transaksi' => 'top_up', 'provider' => 'BRILink Mobile', 'jenis_ewallet' => 'DANA',
            'nomor_ewallet' => str_repeat('1', 31), 'nama_customer' => 'Customer', 'nominal' => 50000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nomor_ewallet');

        $this->postJson('/api/pengeluaran-toko', [
            'tanggal' => now()->toDateString(), 'kategori' => 'Operasional',
            'nama_pengeluaran' => str_repeat('P', 151), 'nominal' => 25000,
        ])->assertUnprocessable()->assertJsonValidationErrors('nama_pengeluaran');

        $barang = Barang::create([
            'kode_barang' => '000BOUNDARY', 'nama_barang' => 'Barang Boundary', 'kategori' => 'Aksesoris',
            'stok' => 10, 'harga_beli' => 10000, 'harga_jual' => 15000,
        ]);
        $this->postJson('/api/retur-supplier', [
            'tanggal_retur' => now()->toDateString(), 'nama_supplier' => str_repeat('S', 151),
            'barang_id' => $barang->id, 'jumlah_retur' => 1, 'alasan_retur' => 'Rusak',
        ])->assertUnprocessable()->assertJsonValidationErrors('nama_supplier');
    }
}
