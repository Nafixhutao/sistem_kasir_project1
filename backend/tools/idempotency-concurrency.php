<?php
// Test database only; all committed fixtures are removed by their exact identities.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
try {
    $base=dirname(__DIR__); TestDatabaseGuard::settings($base);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db);
    $snapshot=function () use ($db) {
        TestDatabaseGuard::assertActual($db); $result=[];
        foreach ($db->select('SHOW TABLES') as $r) {
            $t=array_values((array)$r)[0]; $q=$db->table($t);
            foreach ($db->select('SHOW COLUMNS FROM `'.$t.'`') as $c) if ($c->Key==='PRI') $q->orderBy($c->Field);
            $result[$t]=hash('sha256',json_encode($q->get()));
        }
        return $result;
    };
    $before=$snapshot(); $user=null; $goods=null;
    try {
        TestDatabaseGuard::assertActual($db);
        [$user,$goods,$token]=$db->transaction(function () {
            $u=new App\Models\User(); $u->forceFill(['name'=>'Concurrency fixture','username'=>'idem_race_'.bin2hex(random_bytes(6)),'password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>'kasir','is_active'=>true])->save();
            $g=App\Models\Barang::create(['kode_barang'=>'IDMR-'.bin2hex(random_bytes(6)),'nama_barang'=>'Concurrency fixture','kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000]);
            return [$u,$g,$u->createToken('idempotency-race-fixture')->plainTextToken];
        });
        $race=function (string $url,array $payload,int $expected) use ($base,$token,$db) {
            TestDatabaseGuard::assertActual($db); $processes=[];
            for ($i=0;$i<2;$i++) {
                $p=proc_open([PHP_BINARY,$base.'/tools/idempotency-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$base);
                if (!is_resource($p)) throw new RuntimeException();
                $processes[]=[$p,$pipes];
            }
            foreach ($processes as [$p,$pipes]) if (trim(fgets($pipes[1]))!=='READY') throw new RuntimeException('Worker not ready.');
            $start=microtime(true)+0.4;
            foreach ($processes as [$p,$pipes]) { fwrite($pipes[0],json_encode(['url'=>$url,'payload'=>$payload,'token'=>$token,'start'=>$start])."\n"); fclose($pipes[0]); }
            $results=[];
            foreach ($processes as [$p,$pipes]) {
                $out=stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code=proc_close($p); $r=json_decode(trim($out),true);
                if ($code!==0 || !$r || $r['status']!==$expected || !$r['id']) throw new RuntimeException('Race response failed.');
                $results[]=$r;
            }
            if ($results[0]['id']!==$results[1]['id']) throw new RuntimeException('Duplicate business rows.');
            if ($db->table('idempotency_records')->where('request_id',$payload['request_id'])->where('result_id',$results[0]['id'])->count()!==1) throw new RuntimeException('Duplicate or missing registry receipt.');
            return $results[0]['id'];
        };
        $salePayload=['request_id'=>(string)Str::uuid(),'items'=>[['id'=>$goods->id,'jumlah'=>2]],'metode_pembayaran'=>'tunai','uang_bayar'=>30000];
        $saleId=$race('/api/penjualan',$salePayload,200);
        if (DB::table('transaksi_penjualan')->where('kasir',$user->username)->count()!==1 || DB::table('detail_penjualan')->where('transaksi_penjualan_id',$saleId)->count()!==1 || (int)$goods->fresh()->stok!==8) throw new RuntimeException('Double sale effect.');
        echo 'PASS simultaneous sale: two 200 responses, same row, one detail, stock 10 -> 8 once'.PHP_EOL;
        $detailId=DB::table('detail_penjualan')->where('transaksi_penjualan_id',$saleId)->value('id');
        $returnId=$race('/api/penjualan/'.$saleId.'/retur',['request_id'=>(string)Str::uuid(),'detail_penjualan_id'=>$detailId,'jumlah'=>1,'alasan_retur'=>'Barang Rusak','metode_pengembalian_dana'=>'tunai'],200);
        if (DB::table('retur_pelanggan')->where('transaksi_penjualan_id',$saleId)->count()!==1 || (int)$goods->fresh()->stok!==9) throw new RuntimeException('Double return effect.');
        echo 'PASS simultaneous customer return: two 200 responses, same row, stock 8 -> 9 once'.PHP_EOL;
        $supplierId=$race('/api/retur-supplier',['request_id'=>(string)Str::uuid(),'tanggal_retur'=>today()->toDateString(),'nama_supplier'=>'Concurrency fixture','barang_id'=>$goods->id,'jumlah_retur'=>1,'alasan_retur'=>'Barang rusak'],201);
        if (DB::table('retur_supplier')->where('kasir',$user->username)->count()!==1 || (int)$goods->fresh()->stok!==9) throw new RuntimeException('Double supplier return effect.');
        echo 'PASS simultaneous supplier creation: two 201 responses, same row, no duplicate return'.PHP_EOL;
    } finally {
        // No controller delete/restore flows: exact fixture IDs only, in FK order.
        if ($user && $goods) {
            TestDatabaseGuard::assertActual($db);
            $db->transaction(function () use ($db,$user,$goods) {
                $sales=$db->table('transaksi_penjualan')->where('kasir',$user->username)->pluck('id')->all();
                $customer=$db->table('retur_pelanggan')->whereIn('transaksi_penjualan_id',$sales)->pluck('id')->all();
                $supplier=$db->table('retur_supplier')->where('kasir',$user->username)->pluck('id')->all();
                foreach (['penjualan'=>$sales,'retur_pelanggan'=>$customer,'retur_supplier'=>$supplier] as $scope=>$ids) {
                    $db->table('idempotency_records')->where('scope',$scope)->whereIn('result_id',$ids)->delete();
                }
                $db->table('notifikasi')->where(function ($q) use ($goods,$customer,$supplier) {
                    $q->where(fn($p)=>$p->where('referensi_tipe','barang')->where('referensi_id',$goods->id))
                        ->orWhere(fn($p)=>$p->where('referensi_tipe','retur_pelanggan')->whereIn('referensi_id',$customer))
                        ->orWhere(fn($p)=>$p->where('referensi_tipe','retur_supplier')->whereIn('referensi_id',$supplier));
                })->delete();
                $db->table('retur_pelanggan')->whereIn('id',$customer)->delete();
                $db->table('retur_supplier')->whereIn('id',$supplier)->delete();
                $db->table('detail_penjualan')->whereIn('transaksi_penjualan_id',$sales)->delete();
                $db->table('transaksi_penjualan')->whereIn('id',$sales)->delete();
                $db->table('activity_logs')->where('user_name',$user->username)->delete();
                $db->table('barang')->where('id',$goods->id)->where('kode_barang',$goods->kode_barang)->delete();
                $user->tokens()->delete();
                $db->table('users')->where('id',$user->id)->where('username',$user->username)->delete();
            });
        }
        unset($token);
    }
    if ($snapshot()!==$before) throw new RuntimeException('STOP: persistent baseline changed.');
    echo 'PASS exact fixtures cleaned; all persistent rows/accounts/hashes unchanged'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'STOP: concurrency test failed; details withheld.'.PHP_EOL); exit(1); }
