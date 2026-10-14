<?php
// Local test-only orchestration. Never load the active project or main database.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
try {
    $base=dirname(__DIR__); $root=dirname($base,2);
    TestDatabaseGuard::settings($base);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    $file=$root.'/idempotency-baseline-20261010.json';
    $migration='database/migrations/2026_10_10_000003_complete_business_request_id.php';
    $snapshot=function () use ($db) {
        TestDatabaseGuard::assertActual($db); $result=[];
        foreach ($db->select('SHOW TABLES') as $r) {
            $table=array_values((array)$r)[0]; $columns=$db->select('SHOW COLUMNS FROM `'.$table.'`');
            $query=$db->table($table); $fields=[];
            foreach ($columns as $c) {
                // Newly-added metadata is checked separately; existing columns are exact.
                if ($c->Field !== 'request_hash') $fields[]=$c->Field;
                if ($c->Key==='PRI') $query->orderBy($c->Field);
            }
            $rows=$query->get($fields);
            $result[$table]=['count'=>$rows->count(),'digest'=>hash('sha256',json_encode($rows))];
        }
        ksort($result); return $result;
    };
    $action=$argv[1]??'inspect';
    if ($action==='prepare') {
        if (file_exists($file)) throw new RuntimeException('Baseline exists.');
        $state=$snapshot(); $c=config('database.connections.mysql');
        $backup=$root.'/sistem_project1_test_before_request_id_'.date('Ymd_His').'.sql';
        $env=getenv(); $env['MYSQL_PWD']=$c['password']; TestDatabaseGuard::assertActual($db);
        $p=proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],'--single-transaction','--skip-lock-tables','--skip-add-drop-table','--no-tablespaces','--set-gtid-purged=OFF',TestDatabaseGuard::DATABASE],[0=>['pipe','r'],1=>['file',$backup,'x'],2=>['pipe','w']],$pipes,null,$env);
        unset($env); if (!is_resource($p)) throw new RuntimeException();
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p)!==0 || filesize($backup)===0) throw new RuntimeException();
        $sql=file_get_contents($backup);
        foreach (array_keys($state) as $t) if (!str_contains($sql,'CREATE TABLE `'.$t.'`')) throw new RuntimeException();
        unset($sql); if ($snapshot()!==$state) throw new RuntimeException();
        $h=fopen($file,'x'); fwrite($h,json_encode($state)); fclose($h);
        $manifest=[];
        foreach (['app','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $e) if ($e->isFile()) $manifest[$dir.'/'.substr($e->getPathname(),strlen($base.'/'.$dir)+1)]=hash_file('sha256',$e->getPathname());
        $h=fopen($root.'/idempotency-source-before.json','x'); fwrite($h,json_encode($manifest)); fclose($h);
        echo json_encode(['backup'=>$backup,'bytes'=>filesize($backup),'users'=>$state['users']['count']]).PHP_EOL;
    } elseif ($action==='compare') {
        $old=json_decode(file_get_contents($file),true); $now=$snapshot();
        $history=$db->table('migrations')->where('migration','!=',pathinfo($migration,PATHINFO_FILENAME))->orderBy('id')->get();
        if ($history->count()!==$old['migrations']['count'] || hash('sha256',json_encode($history))!==$old['migrations']['digest']) throw new RuntimeException('Unrelated migration history differs.');
        unset($old['migrations'],$now['migrations']);
        if ($old!==$now) throw new RuntimeException('Persistent data differs.');
        foreach (['transaksi_penjualan','retur_pelanggan','retur_supplier'] as $t) if (\Illuminate\Support\Facades\Schema::hasColumn($t,'request_hash') && $db->table($t)->whereNotNull('request_hash')->exists()) throw new RuntimeException('Fixture metadata remains.');
        echo 'PASS existing accounts/hash/identity/timestamps and every business row unchanged'.PHP_EOL;
        echo 'PASS original migration history unchanged; only the new migration record is allowed'.PHP_EOL;
    } elseif (in_array($action,['preflight','pretend','up','down'],true)) {
        if (!file_exists($file)) throw new RuntimeException('Backup required.');
        TestDatabaseGuard::assertActual($db);
        $instance=require $base.'/'.$migration;
        if ($action==='preflight') { $instance->preflight(); echo 'PASS real preflight'.PHP_EOL; }
        else {
            if ($action==='down') {
                $latest=$db->table('migrations')->orderByDesc('batch')->orderByDesc('id')->first();
                if (!$latest || $latest->migration!==pathinfo($migration,PATHINFO_FILENAME)) throw new RuntimeException('Not latest migration.');
                $code=Artisan::call('migrate:rollback',['--path'=>[$migration],'--step'=>1,'--force'=>true]);
            } else $code=Artisan::call('migrate',['--path'=>[$migration],'--force'=>true,'--pretend'=>$action==='pretend']);
            if ($code!==0) throw new RuntimeException('DDL failed; inspect without retry.');
            echo Artisan::output();
        }
    } else {
        echo json_encode($db->select("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('request_id','request_hash')"),JSON_PRETTY_PRINT).PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'STOP: guarded idempotency stage failed (details withheld).'.PHP_EOL); exit(1); }
