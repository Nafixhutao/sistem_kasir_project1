<?php
// Isolated test-only runner; no database parameter or credential output.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
$base=dirname(__DIR__); $root=dirname($base,2);
$path='database/migrations/2026_10_10_000001_rename_owner_role_to_admin.php';
try {
    TestDatabaseGuard::settings($base);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    $action=$argv[1] ?? 'inspect';
    if ($action==='backup') {
        $file=$root.'/sistem_project1_test_before_admin_role_'.date('Ymd_His').'.sql';
        $c=config('database.connections.mysql'); $env=getenv(); $env['MYSQL_PWD']=$c['password'];
        TestDatabaseGuard::assertActual($db);
        $p=proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],'--single-transaction','--skip-lock-tables','--skip-add-drop-table','--no-tablespaces','--set-gtid-purged=OFF',TestDatabaseGuard::DATABASE],[0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']],$pipes,null,$env);
        unset($env); if (!is_resource($p)) throw new RuntimeException();
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p)!==0 || filesize($file)===0) throw new RuntimeException();
        $sql=file_get_contents($file);
        foreach ($db->select('SHOW TABLES') as $row) if (!str_contains($sql,'CREATE TABLE `'.array_values((array)$row)[0].'`')) throw new RuntimeException();
        unset($sql); echo json_encode(['database'=>TestDatabaseGuard::DATABASE,'file'=>$file,'bytes'=>filesize($file),'time'=>date(DATE_ATOM)]).PHP_EOL;
    } elseif ($action==='manifest') {
        $phase=$argv[2] ?? ''; if (!in_array($phase,['before','after'],true)) throw new RuntimeException();
        $file=$root.'/admin-role-source-'.$phase.'.json'; if (file_exists($file)) throw new RuntimeException();
        $manifest=[];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $manifest[$dir.'/'.substr($entry->getPathname(),strlen($base.'/'.$dir)+1)]=hash_file('sha256',$entry->getPathname());
        ksort($manifest); file_put_contents($file,json_encode($manifest,JSON_PRETTY_PRINT)); echo 'PASS manifest '.count($manifest).' files'.PHP_EOL;
    } elseif ($action==='fixture-start') {
        (require $base.'/'.$path)->preflight(true);
        $file=$root.'/admin-role-migration-fixture.json';
        if (file_exists($file) || $db->table('users')->count()!==2) throw new RuntimeException();
        $name='role_conversion_'.bin2hex(random_bytes(8));
        TestDatabaseGuard::assertActual($db);
        $id=$db->table('users')->insertGetId(['name'=>'Temporary migration check','username'=>$name,'role'=>'pemilik','is_active'=>false,'password'=>\Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(16))),'remember_token'=>bin2hex(random_bytes(16)),'created_at'=>'2026-10-01 00:00:00','updated_at'=>'2026-10-01 00:00:00']);
        $row=(array)$db->table('users')->where('id',$id)->first(); unset($row['role']);
        $state=['id'=>$id,'username'=>$name,'digest'=>hash('sha256',json_encode($row))];
        $handle=fopen($file,'x'); if (!$handle) throw new RuntimeException(); fwrite($handle,json_encode($state)); fclose($handle);
        echo 'PASS isolated migration fixture created'.PHP_EOL;
    } elseif (in_array($action,['fixture-check','fixture-cleanup'],true)) {
        $state=json_decode(file_get_contents($root.'/admin-role-migration-fixture.json'),true,512,JSON_THROW_ON_ERROR);
        $row=(array)$db->table('users')->where('id',$state['id'])->first();
        $role=$row['role'] ?? ''; unset($row['role']);
        if (($row['username'] ?? '')!==$state['username'] || hash('sha256',json_encode($row))!==$state['digest'] || !in_array($role,['admin','pemilik'],true)) throw new RuntimeException();
        if ($action==='fixture-check') {
            if ($role!==($argv[2] ?? '')) throw new RuntimeException();
            echo 'PASS fixture role='.$role.'; ID/name/username/hash/token/status/timestamps unchanged'.PHP_EOL;
        } else {
            TestDatabaseGuard::assertActual($db);
            if ($db->table('users')->where('id',$state['id'])->where('username',$state['username'])->delete()!==1) throw new RuntimeException();
            echo 'PASS exact temporary fixture removed; USERS='.$db->table('users')->count().PHP_EOL;
        }
    } elseif (in_array($action,['pretend','up','down'],true)) {
        $m=require $base.'/'.$path; TestDatabaseGuard::assertActual($db);
        $m->preflight($action!=='down');
        if ($action==='down') {
            $latest=$db->table('migrations')->orderByDesc('batch')->orderByDesc('migration')->first();
            if (!$latest || $latest->migration!==pathinfo($path,PATHINFO_FILENAME)) throw new RuntimeException();
        }
        $args=['--path'=>[$path],'--force'=>true];
        if ($action==='pretend') $args['--pretend']=true;
        if ($action==='down') $args['--step']=1;
        if (Artisan::call($action==='down'?'migrate:rollback':'migrate',$args)!==0) throw new RuntimeException();
        echo Artisan::output();
    } elseif ($action==='inspect') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
        echo array_values((array)$db->selectOne('SHOW CREATE TABLE users'))[1].PHP_EOL;
        echo 'USERS='.$db->table('users')->count().PHP_EOL;
        echo 'ACTIVE_CASHIERS='.$db->table('users')->where('role','kasir')->where('is_active',true)->count().PHP_EOL;
    } else throw new RuntimeException();
} catch (Throwable $e) { fwrite(STDERR,'STOP: guarded admin-role operation failed; details withheld. No automatic retry.'.PHP_EOL); exit(1); }
