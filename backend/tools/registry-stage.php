<?php
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
try {
    $base=dirname(__DIR__); $root=dirname($base,2); TestDatabaseGuard::settings($base);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db);
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    $baseline=$root.'/registry-baseline-20261011.json';
    $migration='database/migrations/2026_10_11_000001_create_idempotency_registry.php';
    $snapshot=function () use ($db) {
        TestDatabaseGuard::assertActual($db); $state=[];
        foreach ($db->select('SHOW TABLES') as $r) {
            $t=array_values((array)$r)[0]; $q=$db->table($t);
            foreach ($db->select('SHOW COLUMNS FROM `'.$t.'`') as $c) if ($c->Key==='PRI') $q->orderBy($c->Field);
            $rows=$q->get(); $state[$t]=['count'=>$rows->count(),'digest'=>hash('sha256',json_encode($rows))];
        }
        ksort($state); return $state;
    };
    $action=$argv[1]??'inspect';
    if ($action==='prepare') {
        if (file_exists($baseline)) throw new RuntimeException('Baseline already exists.');
        $state=$snapshot(); $c=config('database.connections.mysql');
        $backup=$root.'/sistem_project1_test_before_registry_'.date('Ymd_His').'.sql';
        $env=getenv(); $env['MYSQL_PWD']=$c['password']; TestDatabaseGuard::assertActual($db);
        $p=proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],'--single-transaction','--skip-lock-tables','--skip-add-drop-table','--no-tablespaces','--set-gtid-purged=OFF',TestDatabaseGuard::DATABASE],[0=>['pipe','r'],1=>['file',$backup,'x'],2=>['pipe','w']],$pipes,null,$env);
        unset($env); if (!is_resource($p)) throw new RuntimeException();
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p)!==0 || filesize($backup)===0) throw new RuntimeException();
        $sql=file_get_contents($backup);
        foreach (array_keys($state) as $t) if (!str_contains($sql,'CREATE TABLE `'.$t.'`')) throw new RuntimeException();
        unset($sql); if ($snapshot()!==$state) throw new RuntimeException('Baseline changed during backup.');
        $h=fopen($baseline,'x'); fwrite($h,json_encode($state)); fclose($h);
        $manifest=[];
        foreach (['app','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $e) if ($e->isFile()) $manifest[$dir.'/'.substr($e->getPathname(),strlen($base.'/'.$dir)+1)]=hash_file('sha256',$e->getPathname());
        $h=fopen($root.'/registry-source-before.json','x'); fwrite($h,json_encode($manifest)); fclose($h);
        echo json_encode(['backup'=>$backup,'bytes'=>filesize($backup),'users'=>$state['users']['count']]).PHP_EOL;
    } elseif ($action==='compare') {
        $old=json_decode(file_get_contents($baseline),true); $now=$snapshot();
        $history=$db->table('migrations')->where('migration','!=',pathinfo($migration,PATHINFO_FILENAME))->orderBy('id')->get();
        if ($history->count()!==$old['migrations']['count'] || hash('sha256',json_encode($history))!==$old['migrations']['digest']) throw new RuntimeException('Unrelated migration history changed.');
        if (isset($now['idempotency_records']) && $now['idempotency_records']['count']!==0) throw new RuntimeException('Unexpected registry rows remain.');
        unset($old['migrations'],$now['migrations'],$now['idempotency_records']);
        if ($old!==$now) throw new RuntimeException('Persistent baseline changed.');
        echo 'PASS every existing row/account/hash/identity/role/status/timestamp unchanged; registry fixtures absent'.PHP_EOL;
    } elseif (in_array($action,['preflight','pretend','up','down'],true)) {
        if (!file_exists($baseline)) throw new RuntimeException('Backup required.');
        TestDatabaseGuard::assertActual($db); $instance=require $base.'/'.$migration;
        if ($action==='preflight') { $instance->preflight(); echo 'PASS real preflight'.PHP_EOL; }
        else {
            if ($action==='down') {
                $last=$db->table('migrations')->orderByDesc('batch')->orderByDesc('id')->first();
                if (!$last || $last->migration!==pathinfo($migration,PATHINFO_FILENAME)) throw new RuntimeException('Target is not latest.');
                $code=Artisan::call('migrate:rollback',['--path'=>[$migration],'--step'=>1,'--force'=>true]);
            } else $code=Artisan::call('migrate',['--path'=>[$migration],'--force'=>true,'--pretend'=>$action==='pretend']);
            if ($code!==0) throw new RuntimeException('Migration failed; inspect without automatic retry.');
            echo Artisan::output();
        }
    } else {
        foreach (['transaksi_penjualan','retur_pelanggan','retur_supplier'] as $t) echo json_encode(['table'=>$t,'rows'=>$db->table($t)->count(),'used_ids'=>$db->table($t)->whereNotNull('request_id')->count()]).PHP_EOL;
        if (\Illuminate\Support\Facades\Schema::hasTable('idempotency_records')) echo $db->selectOne('SHOW CREATE TABLE idempotency_records')->{'Create Table'}.PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'STOP registry stage failed; sensitive details withheld.'.PHP_EOL); exit(1); }
