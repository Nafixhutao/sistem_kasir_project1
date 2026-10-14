<?php
namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use App\Models\TransaksiPenjualan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessIdempotencyTest extends TestCase
{
    private function goods(): Barang
    {
        Sanctum::actingAs(User::factory()->create());
        return Barang::create(['kode_barang'=>'IDM-'.Str::random(12),'nama_barang'=>'Idempotency fixture','kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000]);
    }

    private function sale(Barang $goods, int $quantity=1): array
    {
        return ['request_id'=>(string)Str::uuid(),'items'=>[['id'=>$goods->id,'jumlah'=>$quantity]],'metode_pembayaran'=>'tunai','uang_bayar'=>15000*$quantity];
    }

    public function test_sale_replay_new_operation_and_payload_conflict(): void
    {
        $goods=$this->goods(); $payload=$this->sale($goods);
        $before=DB::table('transaksi_penjualan')->count();
        $first=$this->postJson('/api/penjualan',$payload)->assertOk();
        $counts=[DB::table('detail_penjualan')->count(),DB::table('activity_logs')->count(),DB::table('notifikasi')->count()];
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$first->json('transaksi.id'));
        $this->assertSame($before+1,DB::table('transaksi_penjualan')->count());
        $this->assertSame($counts,[DB::table('detail_penjualan')->count(),DB::table('activity_logs')->count(),DB::table('notifikasi')->count()]);
        $this->assertSame(9,(int)$goods->fresh()->stok);
        $payload['uang_bayar']=20000;
        $this->postJson('/api/penjualan',$payload)->assertStatus(409);
        $this->assertSame(9,(int)$goods->fresh()->stok);
        $payload['request_id']=(string)Str::uuid();
        $this->postJson('/api/penjualan',$payload)->assertOk();
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->assertSame($before+2,DB::table('transaksi_penjualan')->count());
    }

    public function test_qris_replay_does_not_duplicate_payment_and_new_id_cannot_reuse_order(): void
    {
        $goods=$this->goods(); $payload=$this->sale($goods);
        $payload['metode_pembayaran']='qris'; unset($payload['uang_bayar']);
        $payload['qris_order_id']='IDM-'.Str::uuid();
        $before=DB::table('pembayaran_qris')->count();
        $first=$this->postJson('/api/penjualan',$payload)->assertOk();
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$first->json('transaksi.id'));
        $this->assertSame($before+1,DB::table('pembayaran_qris')->count());
        $payload['request_id']=(string)Str::uuid();
        $this->postJson('/api/penjualan',$payload)->assertUnprocessable();
        $this->assertSame(9,(int)$goods->fresh()->stok);
    }

    public function test_customer_return_replay_changes_stock_once_and_conflicts_are_rejected(): void
    {
        $goods=$this->goods(); $sale=$this->postJson('/api/penjualan',$this->sale($goods,3))->assertOk();
        $url='/api/penjualan/'.$sale->json('transaksi.id').'/retur';
        $payload=['request_id'=>(string)Str::uuid(),'detail_penjualan_id'=>$sale->json('transaksi.details.0.id'),'jumlah'=>1,'alasan_retur'=>'Barang Rusak','metode_pengembalian_dana'=>'tunai'];
        $before=DB::table('retur_pelanggan')->count();
        $first=$this->postJson($url,$payload)->assertOk();
        $counts=[DB::table('activity_logs')->count(),DB::table('notifikasi')->count()];
        $this->postJson($url,$payload)->assertOk()->assertJsonPath('retur.id',$first->json('retur.id'));
        $this->assertSame($counts,[DB::table('activity_logs')->count(),DB::table('notifikasi')->count()]);
        $this->assertSame($before+1,DB::table('retur_pelanggan')->count());
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $payload['jumlah']=2; $this->postJson($url,$payload)->assertStatus(409);
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $payload['jumlah']=1; $payload['request_id']=(string)Str::uuid();
        $this->postJson($url,$payload)->assertOk();
        $this->assertSame(9,(int)$goods->fresh()->stok);
    }

    public function test_supplier_return_creation_replay_and_final_status_guard_stock_once(): void
    {
        $goods=$this->goods();
        $payload=['request_id'=>(string)Str::uuid(),'tanggal_retur'=>today()->toDateString(),'nama_supplier'=>'Idempotency fixture','barang_id'=>$goods->id,'jumlah_retur'=>2,'alasan_retur'=>'Barang rusak','keterangan'=>null];
        $before=DB::table('retur_supplier')->count();
        $first=$this->postJson('/api/retur-supplier',$payload)->assertCreated();
        $id=$first->json('id');
        $this->postJson('/api/retur-supplier',$payload)->assertCreated()->assertJsonPath('id',$id);
        $this->assertSame($before+1,DB::table('retur_supplier')->count());
        $this->assertSame(10,(int)$goods->fresh()->stok);
        $this->putJson('/api/retur-supplier/'.$id,array_merge($payload,['status_retur'=>'diterima']))->assertOk();
        $this->putJson('/api/retur-supplier/'.$id,array_merge($payload,['status_retur'=>'diterima']))->assertUnprocessable();
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->postJson('/api/retur-supplier',$payload)->assertCreated()->assertJsonPath('id',$id);
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $payload['jumlah_retur']=1; $this->postJson('/api/retur-supplier',$payload)->assertStatus(409);
        $payload['request_id']=(string)Str::uuid(); $this->postJson('/api/retur-supplier',$payload)->assertCreated();
        $this->assertSame($before+2,DB::table('retur_supplier')->count());
    }

    public function test_uuid_required_validated_and_actor_cannot_replay_another_actor(): void
    {
        $goods=$this->goods(); $payload=$this->sale($goods);
        unset($payload['request_id']);
        $this->json('POST','/api/penjualan',$payload)->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $payload['request_id']='not-a-uuid'; $this->postJson('/api/penjualan',$payload)->assertUnprocessable();
        $payload['request_id']=(string)Str::uuid(); $this->postJson('/api/penjualan',$payload)->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/penjualan',$payload)->assertStatus(409);
        $this->assertSame(9,(int)$goods->fresh()->stok);
    }

    public function test_database_unique_constraint_and_nullable_legacy_rows_are_preserved(): void
    {
        $goods=$this->goods(); $response=$this->postJson('/api/penjualan',$this->sale($goods))->assertOk();
        $row=TransaksiPenjualan::findOrFail($response->json('transaksi.id'))->getAttributes();
        unset($row['id']); $row['kode_transaksi']='IDM-'.Str::random(12);
        try { DB::table('transaksi_penjualan')->insert($row); $this->fail('Unique constraint must reject duplicate UUID.'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertSame('23000',$e->errorInfo[0]); }
        $this->assertSame(9,(int)$goods->fresh()->stok);
        foreach (['transaksi_penjualan','retur_pelanggan','retur_supplier'] as $table) {
            $index=DB::selectOne('SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$table.'_request_id_unique']);
            $this->assertSame(0,(int)$index->NON_UNIQUE);
        }
    }
}
