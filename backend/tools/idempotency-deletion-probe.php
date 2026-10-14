<?php
// Verify stale retries after the existing supplier-return DELETE. Fixtures roll back.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
try {
    $app=require dirname(__DIR__).'/bootstrap/app.php';
    TestDatabaseGuard::$limiterScope=bin2hex(random_bytes(16)); TestDatabaseGuard::install($app);
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db); $db->beginTransaction();
    try {
        $user=new App\Models\User(); $user->forceFill(['name'=>'Deletion probe','username'=>'idem_delete_'.bin2hex(random_bytes(6)),'password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>'kasir','is_active'=>true])->save();
        $goods=App\Models\Barang::create(['kode_barang'=>'IDMD-'.bin2hex(random_bytes(6)),'nama_barang'=>'Deletion probe','kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000]);
        $token=$user->createToken('deletion-probe')->plainTextToken;
        $call=function ($method,$url,$payload=[]) use ($kernel,$token) {
            $r=Illuminate\Http\Request::create($url,$method,$payload,[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token,'REMOTE_ADDR'=>'198.18.81.42']);
            $response=$kernel->handle($r); $body=json_decode($response->getContent(),true); $status=$response->getStatusCode();
            $kernel->terminate($r,$response); return [$status,$body];
        };
        $payload=['request_id'=>(string)Str::uuid(),'tanggal_retur'=>today()->toDateString(),'nama_supplier'=>'Deletion probe','barang_id'=>$goods->id,'jumlah_retur'=>1,'alasan_retur'=>'Barang rusak'];
        [$first,$body]=$call('POST','/api/retur-supplier',$payload); $firstId=$body['id']??null;
        if ($first!==201 || !$firstId) throw new RuntimeException('Initial fixture failed.');
        [$deleted]=$call('DELETE','/api/retur-supplier/'.$firstId);
        [$retry,$body]=$call('POST','/api/retur-supplier',$payload);
        if ($deleted!==200 || $retry!==409 || isset($body['id']) || (int)$goods->fresh()->stok!==10
            || !$db->table('idempotency_records')->where('scope','retur_supplier')->where('request_id',$payload['request_id'])->exists()) throw new RuntimeException('Durable replay protection failed.');
        echo json_encode(['database'=>TestDatabaseGuard::DATABASE,'first_status'=>$first,'delete_status'=>$deleted,'same_uuid_retry_status'=>$retry,'new_record_after_delete'=>isset($body['id']) && $body['id']!==$firstId,'fixture_rollback'=>true]).PHP_EOL;
    } finally { $db->rollBack(); unset($token); Illuminate\Support\Facades\Cache::store('auth_limiter')->flush(); }
} catch (Throwable $e) { fwrite(STDERR,'STOP deletion probe failed; details withheld.'.PHP_EOL); exit(1); }
