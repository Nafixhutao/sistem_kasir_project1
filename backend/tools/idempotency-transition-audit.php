<?php
// Diagnostic only: temporary fixtures, rollback, no schema changes, test DB only.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
try {
    $base = dirname(__DIR__); $root = dirname($base,2);
    TestDatabaseGuard::settings($base);
    $app = require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    $snapshot = function () use ($db): array {
        TestDatabaseGuard::assertActual($db); $state = [];
        foreach ($db->select('SHOW TABLES') as $r) {
            $table = array_values((array)$r)[0]; $query = $db->table($table);
            foreach ($db->select('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`') as $c) if ($c->Key === 'PRI') $query->orderBy($c->Field);
            $rows = $query->get(); $state[$table] = [$rows->count(),hash('sha256',json_encode($rows))];
        }
        ksort($state); return $state;
    };
    echo json_encode($db->select("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME='request_id' OR TABLE_NAME='users') ORDER BY TABLE_NAME,ORDINAL_POSITION"), JSON_PRETTY_PRINT).PHP_EOL;
    echo json_encode($db->select("SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME='request_id' OR INDEX_NAME IN ('pembayaran_qris_sale_unique','pembayaran_qris_transaction_id_unique')) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"), JSON_PRETTY_PRINT).PHP_EOL;
    echo json_encode($db->table('migrations')->where('migration','like','%idempot%')->get(['migration','batch']), JSON_PRETTY_PRINT).PHP_EOL;
    foreach (glob($root.'/sistem_project1_test_before_*.sql') as $file) {
        $found = false; $h = fopen($file, 'r');
        while (($line = fgets($h)) !== false) if (str_starts_with($line,'INSERT INTO `migrations` VALUES ') && str_contains($line,'2026_08_10_000002_harden_transaction_idempotency')) { $found=true; break; }
        fclose($h); echo json_encode(['backup'=>basename($file),'missing_migration_record_present'=>$found]).PHP_EOL;
    }
    if (($argv[1] ?? '') !== 'probe') exit(0);
    $before = $snapshot(); TestDatabaseGuard::assertActual($db); $db->beginTransaction();
    try {
        $user = new App\Models\User();
        $user->forceFill(['name'=>'Idempotency audit fixture','username'=>'idem_audit_'.bin2hex(random_bytes(6)),'password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>'kasir','is_active'=>true])->save();
        $token = $user->createToken('temporary-idempotency-audit')->plainTextToken;
        $barang = App\Models\Barang::create(['kode_barang'=>'IDA-'.bin2hex(random_bytes(7)),'nama_barang'=>'Audit fixture','kategori'=>'Aksesoris','stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000,'supplier_id'=>null]);
        $payload = ['request_id'=>(string)Str::uuid(),'items'=>[['id'=>$barang->id,'jumlah'=>1]],'metode_pembayaran'=>'tunai','uang_bayar'=>15000];
        $count = $db->table('transaksi_penjualan')->count(); $statuses = [];
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        for ($i=0;$i<2;$i++) {
            $request = Illuminate\Http\Request::create('/api/penjualan','POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token,'REMOTE_ADDR'=>'198.18.99.42']);
            $response = $kernel->handle($request); $statuses[] = $response->getStatusCode();
            $kernel->terminate($request,$response); unset($response);
        }
        $rows = $db->table('transaksi_penjualan')->where('kasir',$user->username)->get(['id','request_id']);
        $result = ['statuses'=>$statuses,'same_request_id_sent_twice'=>true,'created_sales'=>$db->table('transaksi_penjualan')->count()-$count,'stock_before'=>10,'stock_after'=>(int)$barang->fresh()->stok,'saved_request_ids'=>$rows->whereNotNull('request_id')->count()];
        if ($statuses !== [200,200]) throw new RuntimeException('Probe did not reach normal sale flow.');
    } finally {
        $db->rollBack(); unset($token,$payload,$user,$barang);
    }
    if ($snapshot() !== $before) throw new RuntimeException('STOP: persistent baseline changed.');
    echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
    echo 'PASS diagnostic fixtures rolled back; every existing row/account/hash/timestamp unchanged'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'STOP: guarded audit failed; sensitive details withheld.'.PHP_EOL); exit(1); }
