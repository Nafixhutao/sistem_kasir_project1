<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessBackup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionalBackupRestoreTest extends TestCase
{
    private function actor(string $role = 'kasir'): User
    {
        $user = User::factory()->create(); $user->role = $role; $user->save();
        Sanctum::actingAs($user); return $user;
    }

    private function fixtures(): array
    {
        $supplier = Supplier::create(['nama_supplier'=>'Supplier atomic fixture', 'no_telepon'=>null, 'alamat'=>'Alamat test', 'keterangan'=>null]);
        $data = ['nama_barang'=>'Barang atomic fixture','kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000];
        $linked = Barang::create($data+['kode_barang'=>'AT-'.bin2hex(random_bytes(6)), 'supplier_id'=>$supplier->id]);
        $unlinked = Barang::create($data+['kode_barang'=>'AT-'.bin2hex(random_bytes(6)), 'supplier_id'=>null]);
        return [$supplier, $linked, $unlinked];
    }

    private function backup(): array
    {
        return $this->getJson('/api/backup-data')->assertOk()->json();
    }

    private function restore(array $backup)
    {
        return $this->postJson('/api/backup-data/restore', ['backup'=>$backup, 'confirm_text'=>'RESTORE SULTAN CELL']);
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (array_merge(BusinessBackup::TABLES, ['users','personal_access_tokens','notifikasi','migrations','password_reset_tokens']) as $table) {
            $query = DB::table($table);
            $query->orderBy($table === 'password_reset_tokens' ? 'email' : 'id');
            $state[$table] = hash('sha256', json_encode($query->get()));
        }
        return $state;
    }

    public function test_download_includes_suppliers_and_nullable_item_link_but_no_auth_tables(): void
    {
        $this->actor(); [$supplier,$linked,$unlinked] = $this->fixtures();
        $backup = $this->backup();
        $this->assertSame(BusinessBackup::TABLES, array_keys($backup['tables']));
        $this->assertSame($supplier->id, $backup['tables']['suppliers'][0]['id']);
        $goods = array_column($backup['tables']['barang'], null, 'id');
        $this->assertSame($supplier->id, $goods[$linked->id]['supplier_id']);
        $this->assertNull($goods[$unlinked->id]['supplier_id']);
        foreach (['users','personal_access_tokens','password_reset_tokens','notifikasi','migrations'] as $table) $this->assertArrayNotHasKey($table, $backup['tables']);
    }

    public function test_complete_roundtrip_preserves_all_business_rows_and_accounts_with_fk_enabled(): void
    {
        $actor = $this->actor(); [$supplier,$linked,$unlinked] = $this->fixtures();
        // Include normal cash sale + detail rows in the full replacement test.
        $this->postJson('/api/penjualan', ['items'=>[['id'=>$linked->id,'jumlah'=>1]],'metode_pembayaran'=>'tunai','uang_bayar'=>15000])->assertOk();
        // Restore must not silently re-enable providers supplied by the backup.
        DB::table('providers')->orderBy('id')->limit(1)->update(['is_active'=>false]);
        $backup = $this->backup(); $accounts = $this->snapshot();
        Supplier::whereKey($supplier->id)->update(['nama_supplier'=>'Changed after backup']);
        Barang::whereKey($linked->id)->update(['stok'=>2, 'supplier_id'=>null]);
        $extra = Supplier::create(['nama_supplier'=>'Not in backup']);
        $queries = []; DB::listen(function (QueryExecuted $event) use (&$queries) { $queries[] = strtolower($event->sql); });
        $this->restore($backup)->assertOk();
        $this->assertSame('Supplier atomic fixture', $supplier->fresh()->nama_supplier);
        $this->assertSame($supplier->id, $linked->fresh()->supplier_id);
        $this->assertNull($unlinked->fresh()->supplier_id);
        $this->assertDatabaseMissing('suppliers',['id'=>$extra->id]);
        $after = $this->snapshot();
        foreach ($accounts as $table=>$digest) if ($table !== 'activity_logs') $this->assertTrue($digest === $after[$table], 'Row mismatch: '.$table);
        $log = ActivityLog::where('module','Backup')->where('action','restore')->latest('id')->first();
        $this->assertSame($actor->username, $log->user_name);
        $this->assertSame([], $log->properties);
        $this->assertSame(count($backup['tables']['activity_logs'])+1, ActivityLog::count());
        $this->assertSame(1, (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled);
        foreach ($queries as $sql) {
            $this->assertFalse(str_contains($sql, 'truncate'));
            $this->assertFalse(str_contains($sql, 'foreign_key_checks='));
        }
        [$rows, $order] = BusinessBackup::prepare($backup);
        $this->assertTrue(array_search('suppliers', $order, true) < array_search('barang', $order, true));
        $this->assertTrue(array_search('barang', $order, true) < array_search('detail_penjualan', $order, true));
        foreach ($backup['tables'] as $table=>$expectedRows) {
            if ($table === 'activity_logs') continue;
            $this->assertSame(count($expectedRows), DB::table($table)->count());
        }
    }

    public function test_invalid_backups_are_rejected_before_any_write(): void
    {
        $this->actor(); [$supplier,$linked] = $this->fixtures();
        $backup = $this->backup(); $before = $this->snapshot(); $cases = [];
        $bad = $backup; $bad['app'] = 'Other app'; $cases[] = $bad;
        $bad = $backup; unset($bad['tables']['barang']); $cases[] = $bad;
        $bad = $backup; $bad['tables']['suppliers'] = 'invalid'; $cases[] = $bad;
        $bad = $backup; $bad['tables']['users'] = []; $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][0]['unknown_column'] = 'invalid'; $cases[] = $bad;
        $bad = $backup; unset($bad['tables']['barang'][0]['stok']); $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][0]['supplier_id'] = 4294967295; $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][] = $bad['tables']['barang'][0]; $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][0]['nama_barang'] = str_repeat('x', 1000); $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][0]['created_at'] = '2026-02-31 12:00:00'; $cases[] = $bad;
        $bad = $backup; $bad['tables']['barang'][0]['stok'] = 42949672960; $cases[] = $bad;
        $bad = $backup; $bad['tables']['transaksi_penjualan'][0]['metode_pembayaran'] = 'not-an-enum-value'; $cases[] = $bad;
        $bad = $backup; $bad['tables']['activity_logs'][0]['properties'] = '{invalid'; $cases[] = $bad;
        $writes = 0;
        DB::listen(function (QueryExecuted $event) use (&$writes) { if (preg_match('/^\s*(delete|insert|update|alter|truncate)\b/i', $event->sql)) $writes++; });
        foreach ($cases as $bad) {
            $this->restore($bad)->assertUnprocessable()->assertJsonValidationErrors('backup');
            $this->assertTrue($before === $this->snapshot());
        }
        $this->assertSame(0, $writes);
        $this->postJson('/api/backup-data/restore', ['backup'=>'broken JSON', 'confirm_text'=>'RESTORE SULTAN CELL'])->assertUnprocessable();
        $this->restore([])->assertUnprocessable();
        $this->assertTrue($before === $this->snapshot());
    }

    public function test_collation_unique_duplicates_and_orphan_sale_links_fail_preflight(): void
    {
        $this->actor(); $this->fixtures(); $backup = $this->backup(); $before = $this->snapshot();
        $row = $backup['tables']['barang'][0]; $row['id'] = 4294967200; $row['kode_barang'] = strtolower($row['kode_barang']).' ';
        $bad = $backup; $bad['tables']['barang'][] = $row;
        $this->restore($bad)->assertUnprocessable();
        foreach (['barang_id','transaksi_penjualan_id'] as $field) {
            $bad = $backup; $bad['tables']['detail_penjualan'][0][$field] = 4294967295;
            $this->restore($bad)->assertUnprocessable();
        }
        $this->assertTrue($before === $this->snapshot());
    }

    public function test_legacy_backup_without_suppliers_preserves_existing_master_and_null_links(): void
    {
        $this->actor(); $supplier = Supplier::create(['nama_supplier'=>'Retained supplier']);
        $backup = $this->backup(); unset($backup['tables']['suppliers']);
        foreach ($backup['tables']['barang'] as &$row) unset($row['supplier_id']); unset($row);
        $this->restore($backup)->assertOk();
        $this->assertDatabaseHas('suppliers', ['id'=>$supplier->id, 'nama_supplier'=>'Retained supplier']);
        $this->assertSame(0, DB::table('barang')->whereNotNull('supplier_id')->count());
    }

    public function test_missing_optional_child_cannot_cascade_existing_return_rows(): void
    {
        $this->actor(); $backup = $this->backup(); $before = $this->snapshot();
        unset($backup['tables']['retur_supplier']);
        $this->restore($backup)->assertUnprocessable();
        $this->assertTrue($before === $this->snapshot());
    }

    public function test_failure_after_child_deletes_and_parent_inserts_rolls_back_every_table(): void
    {
        $this->actor(); [$supplier,$linked] = $this->fixtures();
        $backup = $this->backup(); $before = $this->snapshot();
        $failed = false; $deleted = []; $inserted = [];
        DB::listen(function (QueryExecuted $event) use (&$failed,&$deleted,&$inserted) {
            if (preg_match('/^delete from `([^`]+)`/i', $event->sql, $m)) $deleted[] = $m[1];
            if (preg_match('/^insert into `([^`]+)`/i', $event->sql, $m)) $inserted[] = $m[1];
            if (!$failed && str_starts_with(strtolower($event->sql), 'insert into `barang`')) {
                $failed = true; throw new \RuntimeException('Injected test-only failure');
            }
        });
        $this->restore($backup)->assertStatus(500)->assertExactJson(['message'=>'Restore gagal. Seluruh perubahan data dibatalkan.']);
        $this->assertTrue($failed); $this->assertCount(count(BusinessBackup::TABLES), $deleted);
        $this->assertContains('suppliers', $inserted); $this->assertContains('barang', $inserted);
        $this->assertTrue($before === $this->snapshot(), 'No table may be partially restored.');
        $this->assertSame($supplier->id, $linked->fresh()->supplier_id);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled);
        $this->restore($backup)->assertOk(); // A later explicit attempt succeeds after rollback.
    }

    public function test_audit_write_failure_also_rolls_back_complete_restore(): void
    {
        $this->actor(); $this->fixtures(); $backup = $this->backup(); $before = $this->snapshot();
        $failed = false;
        ActivityLog::creating(function ($log) use (&$failed) {
            if ($log->module === 'Backup' && $log->action === 'restore') { $failed = true; throw new \RuntimeException('Injected audit failure'); }
        });
        $this->restore($backup)->assertStatus(500); $this->assertTrue($failed);
        $this->assertTrue($before === $this->snapshot());
    }

    public function test_backup_authorization_is_unchanged_admin_forbidden_anonymous_unauthorized(): void
    {
        $this->getJson('/api/backup-data')->assertUnauthorized();
        $this->postJson('/api/backup-data/restore', [])->assertUnauthorized();
        $this->actor('admin'); $before = $this->snapshot();
        $this->getJson('/api/backup-data')->assertForbidden();
        $this->postJson('/api/backup-data/restore', [])->assertForbidden();
        $this->assertTrue($before === $this->snapshot());
    }

    public function test_restore_preserves_request_ids_and_qris_duplicate_order_protection(): void
    {
        $this->actor(); [$supplier,$linked] = $this->fixtures();
        $order = 'ATOMIC-QRIS-'.bin2hex(random_bytes(8));
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $request = ['request_id'=>$uuid,'items'=>[['id'=>$linked->id,'jumlah'=>1]], 'metode_pembayaran'=>'qris', 'qris_order_id'=>$order];
        $sale = $this->postJson('/api/penjualan', $request)->assertOk()->json('transaksi.id');
        $backup = $this->backup();
        $this->restore($backup)->assertOk();
        $this->assertDatabaseHas('transaksi_penjualan', ['id'=>$sale,'request_id'=>$uuid]);
        $stock = $linked->fresh()->stok; $count = DB::table('transaksi_penjualan')->count();
        $request['request_id']=(string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/penjualan', $request)->assertUnprocessable()->assertJsonValidationErrors('qris_order_id');
        $this->assertSame($stock, $linked->fresh()->stok);
        $this->assertSame($count, DB::table('transaksi_penjualan')->count());
        $duplicate = (array) DB::table('transaksi_penjualan')->where('id',$sale)->first();
        $duplicate['id'] = 4294967200; $duplicate['kode_transaksi'] = 'ATOMIC-DUPLICATE';
        $bad = $backup; $bad['tables']['transaksi_penjualan'][] = $duplicate; $before = $this->snapshot();
        $this->restore($bad)->assertUnprocessable();
        $this->assertTrue($before === $this->snapshot());
        $rejected = false;
        try { DB::table('transaksi_penjualan')->insert($duplicate); }
        catch (\Illuminate\Database\QueryException $e) { $rejected = (int) $e->errorInfo[1] === 1062; }
        $this->assertTrue($rejected, 'Existing test request_id unique index must remain intact.');
    }

    public function test_restore_preserves_fingerprint_and_sale_replay_without_second_stock_effect(): void
    {
        $this->actor(); [$supplier,$goods]=$this->fixtures();
        $payload=['request_id'=>(string)\Illuminate\Support\Str::uuid(),'items'=>[['id'=>$goods->id,'jumlah'=>1]],'metode_pembayaran'=>'tunai','uang_bayar'=>15000];
        $first=$this->postJson('/api/penjualan',$payload)->assertOk();
        $id=$first->json('transaksi.id');
        $fingerprint=DB::table('transaksi_penjualan')->where('id',$id)->value('request_hash');
        $backup=$this->backup(); $this->restore($backup)->assertOk();
        $this->assertTrue(hash_equals($fingerprint,DB::table('transaksi_penjualan')->where('id',$id)->value('request_hash')));
        $count=DB::table('transaksi_penjualan')->count();
        $this->postJson('/api/penjualan',$payload)->assertOk()->assertJsonPath('transaksi.id',$id);
        $this->assertSame($count,DB::table('transaksi_penjualan')->count());
        $this->assertSame(9,(int)$goods->fresh()->stok);
    }
}
