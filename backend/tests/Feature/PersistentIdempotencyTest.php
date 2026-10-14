<?php
namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Models\IdempotencyRecord;
use App\Support\BusinessBackup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersistentIdempotencyTest extends TestCase
{
    private function goods(): Barang
    {
        Sanctum::actingAs(User::factory()->create());
        $supplier=Supplier::create(['nama_supplier'=>'Registry fixture']);
        return Barang::create(['kode_barang'=>'REG-'.Str::random(12),'nama_barang'=>'Registry fixture','supplier_id'=>$supplier->id,
            'kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000]);
    }

    private function supplierPayload(Barang $goods): array
    {
        return ['request_id'=>(string)Str::uuid(),'tanggal_retur'=>today()->toDateString(),'nama_supplier'=>'Registry fixture',
            'barang_id'=>$goods->id,'jumlah_retur'=>2,'alasan_retur'=>'Barang rusak'];
    }

    private function salePayload(Barang $goods): array
    {
        return ['request_id'=>(string)Str::uuid(),'items'=>[['id'=>$goods->id,'jumlah'=>2]],'metode_pembayaran'=>'tunai','uang_bayar'=>30000];
    }

    private function backup(): array { return $this->getJson('/api/backup-data')->assertOk()->json(); }
    private function restore(array $backup) { return $this->postJson('/api/backup-data/restore',['backup'=>$backup,'confirm_text'=>'RESTORE SULTAN CELL']); }
    private function snapshot(): array
    {
        $state=[];
        foreach (array_merge(BusinessBackup::TABLES,['users','personal_access_tokens','notifikasi','migrations']) as $t) {
            $state[$t]=hash('sha256',json_encode(DB::table($t)->orderBy('id')->get()));
        }
        return $state;
    }

    public function test_deleted_supplier_return_key_survives_and_stock_has_no_second_effect(): void
    {
        $goods=$this->goods(); $payload=$this->supplierPayload($goods); $before=DB::table('retur_supplier')->count();
        $id=$this->postJson('/api/retur-supplier',$payload)->assertCreated()->json('id');
        $this->assertSame(10,(int)$goods->fresh()->stok); // Existing create does not change stock.
        $this->putJson('/api/retur-supplier/'.$id,$payload+['status_retur'=>'diterima'])->assertOk();
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->deleteJson('/api/retur-supplier/'.$id)->assertOk();
        $this->assertSame(10,(int)$goods->fresh()->stok); // Existing delete reverses accepted return.
        $state=$this->snapshot();
        $this->postJson('/api/retur-supplier',$payload)->assertStatus(409);
        $this->assertTrue($state===$this->snapshot());
        $this->assertSame($before,DB::table('retur_supplier')->count());
        $this->assertDatabaseHas('idempotency_records',['scope'=>'retur_supplier','request_id'=>$payload['request_id'],'result_id'=>$id,'status'=>'completed']);
        $payload['jumlah_retur']=1; $this->postJson('/api/retur-supplier',$payload)->assertStatus(409);
        $payload['request_id']=(string)Str::uuid(); $this->postJson('/api/retur-supplier',$payload)->assertCreated();
    }

    public function test_cancelled_sale_replays_cancelled_result_without_reducing_stock(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods);
        $id=$this->postJson('/api/penjualan',$payload)->assertOk()->json('transaksi.id');
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->postJson('/api/penjualan/'.$id.'/cancel',['alasan'=>'Pembatalan fixture registry'])->assertOk();
        $this->assertSame(10,(int)$goods->fresh()->stok); $state=$this->snapshot();
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$id)->assertJsonPath('transaksi.status','batal');
        $this->assertTrue($state===$this->snapshot());
    }

    public function test_scope_namespace_and_database_unique_constraint(): void
    {
        $goods=$this->goods(); $sale=$this->salePayload($goods);
        $this->postJson('/api/penjualan',$sale)->assertOk();
        $supplier=$this->supplierPayload($goods); $supplier['request_id']=$sale['request_id'];
        $this->postJson('/api/retur-supplier',$supplier)->assertCreated();
        $this->assertSame(2,IdempotencyRecord::where('request_id',$sale['request_id'])->count());
        $r=(array)DB::table('idempotency_records')->where('scope','penjualan')->where('request_id',$sale['request_id'])->first(); unset($r['id']);
        try { DB::table('idempotency_records')->insert($r); $this->fail('Unique scope/key required.'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertSame(1062,(int)$e->errorInfo[1]); }
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->assertSame(0,(int)DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='idempotency_records' AND REFERENCED_TABLE_NAME IS NOT NULL")->n);
        $this->assertArrayNotHasKey('request_hash',IdempotencyRecord::first()->toArray());
    }

    public function test_registry_insert_failure_rolls_back_business_stock_and_audit_then_retry_succeeds(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods); $before=$this->snapshot(); $failed=false;
        DB::listen(function(QueryExecuted $e) use (&$failed) {
            if (!$failed && str_starts_with(strtolower($e->sql),'insert into `idempotency_records`')) {
                $failed=true; throw new \RuntimeException('Injected registry test failure');
            }
        });
        $this->postJson('/api/penjualan',$payload)->assertStatus(500);
        $this->assertTrue($failed); $this->assertTrue($before===$this->snapshot());
        $this->postJson('/api/penjualan',$payload)->assertOk();
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->assertSame(1,IdempotencyRecord::where('request_id',$payload['request_id'])->count());
    }

    public function test_legacy_processed_row_is_adopted_without_second_business_effect(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods);
        $id=$this->postJson('/api/penjualan',$payload)->assertOk()->json('transaksi.id');
        // Test-only simulate the prior implementation, before registry was introduced.
        DB::table('idempotency_records')->where('scope','penjualan')->where('request_id',$payload['request_id'])->delete();
        $counts=[DB::table('detail_penjualan')->count(),DB::table('activity_logs')->count(),DB::table('notifikasi')->count()];
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$id);
        $this->assertSame($counts,[DB::table('detail_penjualan')->count(),DB::table('activity_logs')->count(),DB::table('notifikasi')->count()]);
        $this->assertSame(8,(int)$goods->fresh()->stok);
        $this->assertDatabaseHas('idempotency_records',['scope'=>'penjualan','request_id'=>$payload['request_id'],'result_id'=>$id]);
    }

    public function test_backup_roundtrip_restores_result_and_registry_with_supplier_links(): void
    {
        $goods=$this->goods(); $payload=$this->supplierPayload($goods);
        $id=$this->postJson('/api/retur-supplier',$payload)->assertCreated()->json('id');
        $backup=$this->backup(); $this->assertNotEmpty($backup['tables']['idempotency_records']);
        $this->deleteJson('/api/retur-supplier/'.$id)->assertOk();
        $this->restore($backup)->assertOk(); $state=$this->snapshot();
        $this->postJson('/api/retur-supplier',$payload)->assertCreated()->assertJsonPath('id',$id);
        $this->assertTrue($state===$this->snapshot()); $this->assertSame(10,(int)$goods->fresh()->stok);
        $this->assertNotNull($goods->fresh()->supplier_id);
        $this->assertDatabaseHas('suppliers',['id'=>$goods->supplier_id]);
        $this->assertSame(1,(int)DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled);
    }

    public function test_older_and_legacy_backups_cannot_erase_completed_keys_or_allow_replay(): void
    {
        $goods=$this->goods(); $old=$this->backup(); $payload=$this->supplierPayload($goods);
        $id=$this->postJson('/api/retur-supplier',$payload)->assertCreated()->json('id');
        $this->deleteJson('/api/retur-supplier/'.$id)->assertOk();
        foreach ([true,false] as $includeRegistry) {
            $backup=$old; if (!$includeRegistry) unset($backup['tables']['idempotency_records']);
            $this->restore($backup)->assertOk(); $state=$this->snapshot();
            $this->postJson('/api/retur-supplier',$payload)->assertStatus(409);
            $this->assertTrue($state===$this->snapshot());
            $this->assertDatabaseHas('idempotency_records',['scope'=>'retur_supplier','request_id'=>$payload['request_id'],'result_id'=>$id]);
        }
        $tombstone=$this->backup(); $this->restore($tombstone)->assertOk();
        $this->postJson('/api/retur-supplier',$payload)->assertStatus(409);
    }

    public function test_conflicting_or_invalid_backup_registry_fails_before_writes(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods); $this->postJson('/api/penjualan',$payload)->assertOk();
        $backup=$this->backup(); $before=$this->snapshot(); $cases=[];
        foreach (['request_hash'=>str_repeat('0',64),'scope'=>'unsupported','request_id'=>'invalid','result_id'=>0] as $field=>$value) {
            $bad=$backup; $bad['tables']['idempotency_records'][0][$field]=$value; $cases[]=$bad;
        }
        $bad=$backup; $bad['tables']['idempotency_records'][]=$bad['tables']['idempotency_records'][0]; $cases[]=$bad;
        $bad=$backup; foreach ($bad['tables']['transaksi_penjualan'] as &$r) if ($r['request_id']===$payload['request_id']) $r['request_hash']=null; unset($r); $cases[]=$bad;
        $writes=0; DB::listen(function(QueryExecuted $e) use (&$writes) { if (preg_match('/^\s*(delete|insert|update)\b/i',$e->sql)) $writes++; });
        foreach ($cases as $bad) { $this->restore($bad)->assertUnprocessable(); $this->assertTrue($before===$this->snapshot()); }
        $this->assertSame(0,$writes);
    }

    public function test_restore_failure_after_registry_insert_rolls_back_everything(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods); $this->postJson('/api/penjualan',$payload)->assertOk();
        $backup=$this->backup(); $before=$this->snapshot(); $failed=false;
        DB::listen(function(QueryExecuted $e) use (&$failed) {
            if (!$failed && str_starts_with(strtolower($e->sql),'insert into `idempotency_records`')) { $failed=true; throw new \RuntimeException('Injected restore registry failure'); }
        });
        $this->restore($backup)->assertStatus(500); $this->assertTrue($failed);
        $this->assertTrue($before===$this->snapshot());
        $this->restore($backup)->assertOk();
        $this->postJson('/api/penjualan',$payload)->assertOk(); $this->assertSame(8,(int)$goods->fresh()->stok);
    }

    public function test_legacy_backup_reconstructs_receipt_from_verifiable_business_metadata(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods);
        $id=$this->postJson('/api/penjualan',$payload)->assertOk()->json('transaksi.id');
        $backup=$this->backup(); unset($backup['tables']['idempotency_records']);
        DB::table('idempotency_records')->where('scope','penjualan')->where('request_id',$payload['request_id'])->delete();
        $this->restore($backup)->assertOk();
        $this->assertDatabaseHas('idempotency_records',['scope'=>'penjualan','request_id'=>$payload['request_id'],'result_id'=>$id]);
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$id);
        $this->assertSame(8,(int)$goods->fresh()->stok);
    }

    public function test_restore_remaps_only_internal_id_collisions_and_preserves_both_completed_keys(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods); $this->postJson('/api/penjualan',$payload)->assertOk();
        $backup=$this->backup(); $original=$backup['tables']['idempotency_records'][0];
        $other=$original; $other['request_id']=(string)Str::uuid();
        $backup['tables']['idempotency_records']=[$other];
        $this->restore($backup)->assertOk();
        $stored=DB::table('idempotency_records')->where('request_id',$original['request_id'])->first();
        $extra=DB::table('idempotency_records')->where('request_id',$other['request_id'])->first();
        $this->assertSame($original['id'],$stored->id);
        $this->assertNotSame($stored->id,$extra->id);
        $this->assertTrue(hash_equals($original['request_hash'],$stored->request_hash));
        $this->postJson('/api/penjualan',$payload)->assertOk(); $this->assertSame(8,(int)$goods->fresh()->stok);
    }

    public function test_rollback_refuses_to_destroy_completed_receipts(): void
    {
        $goods=$this->goods(); $payload=$this->salePayload($goods); $this->postJson('/api/penjualan',$payload)->assertOk();
        $migration=require database_path('migrations/2026_10_11_000001_create_idempotency_registry.php');
        $before=$this->snapshot(); $refused=false;
        try { $migration->down(); } catch (\RuntimeException $e) { $refused=true; }
        $this->assertTrue($refused); $this->assertTrue($before===$this->snapshot());
    }
}
