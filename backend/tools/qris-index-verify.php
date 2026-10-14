<?php
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

try {
    $base=dirname(__DIR__); $root=dirname($base,2);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db);
    $name='2026_10_11_000004_complete_qris_unique_indexes';
    $path='database/migrations/'.$name.'.php';
    $baseline=$root.'/qris-index-baseline-20261011.json';
    $action=$argv[1]??'inspect';
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    $snapshot=function () use ($db,$name): array {
        TestDatabaseGuard::assertActual($db); $state=[];
        foreach ($db->select('SHOW TABLES') as $r) {
            $t=array_values((array)$r)[0]; $q=$db->table($t);
            if ($t==='migrations') $q->where('migration','!=',$name);
            foreach ($db->select('SHOW COLUMNS FROM `'.$t.'`') as $c) if ($c->Key==='PRI') $q->orderBy($c->Field);
            $rows=$q->get(); $state[$t]=['count'=>$rows->count(),'digest'=>hash('sha256',json_encode($rows))];
        }
        ksort($state); return $state;
    };
    if ($action==='prepare') {
        if (file_exists($baseline)) throw new RuntimeException('Existing baseline must not be overwritten.');
        $state=$snapshot(); $c=config('database.connections.mysql');
        $backup=$root.'/sistem_project1_test_before_qris_indexes_'.date('Ymd_His').'.sql';
        $env=getenv(); $env['MYSQL_PWD']=$c['password']; TestDatabaseGuard::assertActual($db);
        $p=proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],'--single-transaction','--skip-lock-tables','--skip-add-drop-table','--no-tablespaces','--set-gtid-purged=OFF',TestDatabaseGuard::DATABASE],[0=>['pipe','r'],1=>['file',$backup,'x'],2=>['pipe','w']],$pipes,null,$env);
        unset($env); if (!is_resource($p)) throw new RuntimeException('Backup process unavailable.');
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p)!==0 || filesize($backup)===0) throw new RuntimeException('Backup failed.');
        $sql=file_get_contents($backup);
        foreach (array_keys($state) as $t) if (!str_contains($sql,'CREATE TABLE `'.$t.'`')) throw new RuntimeException('Incomplete backup.');
        unset($sql); if ($snapshot()!==$state) throw new RuntimeException('Data changed during backup.');
        $h=fopen($baseline,'x'); fwrite($h,json_encode($state)); fclose($h);
        $manifest=[];
        foreach (['app','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $e) if ($e->isFile()) $manifest[$dir.'/'.substr($e->getPathname(),strlen($base.'/'.$dir)+1)]=hash_file('sha256',$e->getPathname());
        $h=fopen($root.'/qris-index-source-before.json','x'); fwrite($h,json_encode($manifest)); fclose($h);
        echo json_encode(['backup'=>basename($backup),'bytes'=>filesize($backup),'users'=>$state['users']['count'],'tables'=>count($state)]).PHP_EOL;
    } elseif ($action==='compare') {
        if ($snapshot()!==json_decode(file_get_contents($baseline),true)) throw new RuntimeException('Persistent data changed.');
        $old=json_decode(file_get_contents($root.'/qris-index-source-before.json'),true);
        foreach ($old as $file=>$digest) if (hash_file('sha256',$base.'/'.$file)!==$digest) throw new RuntimeException('Existing source changed.');
        echo 'PASS all persistent rows, account identities/passwords/timestamps and historical migrations unchanged; only new migration history allowed'.PHP_EOL;
    } elseif (in_array($action,['preflight','pretend','up','down'],true)) {
        if (!file_exists($baseline)) throw new RuntimeException('Backup/baseline required.');
        TestDatabaseGuard::assertActual($db); $migration=require $base.'/'.$path;
        if ($action==='preflight') { $migration->preflight(); echo 'PASS real data/index preflight'.PHP_EOL; }
        else {
            if ($action==='down') {
                $last=$db->table('migrations')->orderByDesc('batch')->orderByDesc('id')->first();
                if (!$last || $last->migration!==$name) throw new RuntimeException('Target is not latest.');
                $code=Artisan::call('migrate:rollback',['--path'=>[$path],'--step'=>1,'--force'=>true]);
            } else $code=Artisan::call('migrate',['--path'=>[$path],'--force'=>true,'--pretend'=>$action==='pretend']);
            if ($code!==0) throw new RuntimeException('Migration failed; inspect without automatic retry.');
            echo Artisan::output();
        }
    } elseif ($action==='simulate') {
        $before=$snapshot();
        $original=array_values((array)$db->selectOne('SHOW CREATE TABLE `pembayaran_qris`'))[1];
        $legacy=preg_replace('/^\s*(?:UNIQUE KEY `pembayaran_qris_(?:sale|transaction_id)_unique`|CONSTRAINT `pembayaran_qris_).*\n/m','',$original);
        $legacy=preg_replace('/,\n\)/',"\n)",$legacy);
        $legacy=str_replace('CREATE TABLE `pembayaran_qris`','CREATE TEMPORARY TABLE `pembayaran_qris`',$legacy);
        TestDatabaseGuard::assertActual($db); $db->statement($legacy);
        $rows=function () use ($db) { return hash('sha256',json_encode($db->table('pembayaran_qris')->orderBy('id')->get())); };
        $fixture=['order_id'=>'qris-private-fixture-1','transaksi_penjualan_id'=>900000001,'nominal'=>'12000.00','transaction_id'=>null];
        TestDatabaseGuard::assertActual($db); $db->table('pembayaran_qris')->insert($fixture);
        $digest=$rows(); $load=fn()=>require $base.'/'.$path;
        $assert=function(bool $ok,string $message) { if (!$ok) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; };
        $load()->up(); $load()->up();
        $assert($rows()===$digest,'new indexes preserve rows; repeated up is safe');
        $indexes=$db->select('SHOW INDEX FROM pembayaran_qris');
        $assert(count(array_filter($indexes,fn($i)=>str_ends_with($i->Key_name,'sale_unique')||str_ends_with($i->Key_name,'transaction_id_unique')))===2,'both new full-column UNIQUE keys created once');
        $expectDuplicate=function (array $row) use ($db,$assert): void {
            try { $db->table('pembayaran_qris')->insert($row); throw new RuntimeException('Duplicate incorrectly accepted.'); }
            catch (Illuminate\Database\QueryException $e) { $assert(($e->errorInfo[1]??0)===1062,'duplicate rejected by database'); }
        };
        $expectDuplicate(array_replace($fixture,['order_id'=>'qris-private-dup-sale']));
        $db->table('pembayaran_qris')->insert(array_replace($fixture,['order_id'=>'qris-private-fixture-2','transaksi_penjualan_id'=>900000002]));
        $assert($db->table('pembayaran_qris')->whereNull('transaction_id')->count()===2,'multiple NULL transaction IDs allowed');
        $db->table('pembayaran_qris')->insert(array_replace($fixture,['order_id'=>'qris-private-fixture-3','transaksi_penjualan_id'=>900000003,'transaction_id'=>'qris-private-midtrans-id']));
        $expectDuplicate(array_replace($fixture,['order_id'=>'qris-private-dup-tx','transaksi_penjualan_id'=>900000004,'transaction_id'=>'qris-private-midtrans-id']));
        $digest=$rows(); $load()->down();
        $assert($rows()===$digest,'rollback preserves all payment data');
        $remaining=$db->select('SHOW INDEX FROM pembayaran_qris');
        $assert(count($remaining)===3,'rollback removes only owned keys; primary/order/FK-support kept');
        // One legacy key, one missing key: down must retain the legacy key.
        $db->statement('ALTER TABLE pembayaran_qris ADD UNIQUE INDEX pembayaran_qris_sale_unique (transaksi_penjualan_id)');
        $load()->up(); $load()->down();
        $assert(count($db->select('SHOW INDEX FROM pembayaran_qris'))===4,'mixed legacy/new ownership rollback safe across migration instances');
        $load()->up();
        $assert($rows()===$digest,'reapply successful without row mutation');
        // Close private connection; temporary fixtures vanish, not persistent QRIS rows.
        DB::disconnect(); $db=DB::connection(); TestDatabaseGuard::assertActual($db);
        $assert($snapshot()===$before,'all persistent tables unchanged after fixture connection closed');
    } else {
        echo array_values((array)$db->selectOne('SHOW CREATE TABLE pembayaran_qris'))[1].PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'STOP QRIS verification; sensitive details withheld.'.PHP_EOL); exit(1); }
