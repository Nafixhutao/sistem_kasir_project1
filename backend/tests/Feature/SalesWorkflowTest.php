<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\PembayaranQris;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesWorkflowTest extends TestCase
{
    public function test_cash_qris_invoice_cancel_return_and_reports_work_after_key_normalization(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $barang = Barang::create([
            'kode_barang' => '000SALE001',
            'nama_barang' => 'Barang Penjualan Test',
            'kategori' => 'Aksesoris',
            'stok' => 10,
            'harga_beli' => 10000,
            'harga_jual' => 15000,
        ]);

        $cash = $this->postJson('/api/penjualan', [
            'items' => [['id' => $barang->id, 'jumlah' => 2]],
            'metode_pembayaran' => 'tunai',
            'uang_bayar' => 50000,
        ])->assertOk()->assertJsonPath('transaksi.metode_pembayaran', 'tunai');
        $saleId = $cash->json('transaksi.id');
        $detailId = $cash->json('transaksi.details.0.id');
        $this->assertSame(8, (int) $barang->fresh()->stok);

        $this->getJson('/api/penjualan/invoice/'.$saleId)
            ->assertOk()
            ->assertJsonPath('details.0.kode_barang', '000SALE001');

        $this->postJson('/api/penjualan/'.$saleId.'/retur', [
            'detail_penjualan_id' => $detailId,
            'jumlah' => 1,
            'alasan_retur' => 'Barang Rusak',
            'metode_pengembalian_dana' => 'tunai',
            'keterangan' => 'Pengujian retur pelanggan',
        ])->assertOk()
            ->assertJsonPath('retur.kode_barang', '000SALE001')
            ->assertJsonPath('retur.metode_pengembalian_dana', 'tunai');
        $this->assertSame(9, (int) $barang->fresh()->stok);

        $this->postJson('/api/penjualan/'.$saleId.'/cancel', ['alasan' => 'Pembatalan pengujian'])
            ->assertOk()->assertJsonPath('transaksi.status', 'batal');
        $this->assertSame(10, (int) $barang->fresh()->stok);

        Http::preventStrayRequests();
        // Isolated suite must never depend on a real payment credential.
        config(['services.midtrans.server_key' => bin2hex(random_bytes(24))]);
        Http::fake([
            '*v2/charge' => Http::response([
                'transaction_status' => 'pending',
                'actions' => [['name' => 'generate-qr-code', 'url' => 'https://sandbox.example/qris.png']],
            ], 201),
            '*status' => Http::response([
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'payment_type' => 'qris',
                'gross_amount' => '15000.00',
            ]),
        ]);

        $qrisRequest = $this->postJson('/api/penjualan/qris', [
            'items' => [['id' => $barang->id, 'jumlah' => 1]],
        ])->assertOk()->assertJsonPath('status', 'pending');
        $orderId = $qrisRequest->json('order_id');
        $this->assertLessThanOrEqual(100, strlen($orderId));

        $this->postJson('/api/penjualan/qris/status', ['order_id' => $orderId])
            ->assertOk()->assertJsonPath('status', 'settlement');

        $qrisSale = $this->postJson('/api/penjualan', [
            'items' => [['id' => $barang->id, 'jumlah' => 1]],
            'metode_pembayaran' => 'qris',
            'qris_order_id' => $orderId,
        ])->assertOk()->assertJsonPath('transaksi.metode_pembayaran', 'qris');
        $qris = PembayaranQris::where('order_id', $orderId)->firstOrFail();
        $this->assertSame((int) $qrisSale->json('transaksi.id'), (int) $qris->transaksi_penjualan_id);

        $statusCode = '200';
        $grossAmount = '15000.00';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.config('services.midtrans.server_key'));
        $this->postJson('/api/midtrans/notification', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => '000-MIDTRANS-TEST',
        ])->assertOk();
        $this->assertDatabaseHas('pembayaran_qris', [
            'order_id' => $orderId,
            'transaction_id' => '000-MIDTRANS-TEST',
            'status_pembayaran' => 'settlement',
        ]);

        $this->getJson('/api/laporan/penjualan')->assertOk();
        $this->getJson('/api/laporan/retur-pelanggan')->assertOk()
            ->assertJsonFragment(['kode_barang' => '000SALE001']);
        Http::assertSentCount(2);
    }

    public function test_unsigned_integer_id_boundaries_and_numeric_routes_are_enforced(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertTrue(Validator::make(
            ['id' => 4294967295],
            ['id' => ['required', 'integer', 'min:1', 'max:4294967295']]
        )->passes());

        foreach ([0, -1, 4294967296, 'bukan-angka'] as $invalidId) {
            $this->postJson('/api/penjualan', [
                'items' => [['id' => $invalidId, 'jumlah' => 1]],
                'metode_pembayaran' => 'tunai',
                'uang_bayar' => 10000,
            ])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        }

        $this->getJson('/api/penjualan/invoice/bukan-angka')->assertNotFound();
        $this->getJson('/api/penjualan/invoice/-1')->assertNotFound();
        $this->getJson('/api/penjualan/invoice/0')->assertNotFound();
        $this->getJson('/api/penjualan/invoice/4294967296')->assertNotFound();
    }
}
