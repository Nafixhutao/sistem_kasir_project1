<?php
// Read-only audit/baseline for the isolated test database; never prints secrets.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
$base=dirname(__DIR__); $root=dirname($base,2); $file=$root.'/fee-access-baseline-20261010.json';
try {
    TestDatabaseGuard::settings($base); $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $db=DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    $state=[];
    foreach ($db->select('SHOW TABLES') as $r) {
        $table=array_values((array)$r)[0]; $q=$db->table($table);
        foreach ($db->select('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`') as $c) if ($c->Key==='PRI') $q->orderBy($c->Field);
        $rows=$q->get(); $state[$table]=['count'=>$rows->count(),'digest'=>hash('sha256',json_encode($rows))];
    }
    ksort($state); $action=$argv[1] ?? 'audit';
    if ($action==='baseline') {
        if (file_exists($file)) throw new RuntimeException();
        $h=fopen($file,'x'); fwrite($h,json_encode($state,JSON_PRETTY_PRINT)); fclose($h);
        echo 'PASS private baseline; DATABASE='.TestDatabaseGuard::DATABASE.'; USERS='.$state['users']['count'].PHP_EOL;
    } elseif ($action==='compare') {
        if ($state!==json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)) throw new RuntimeException();
        echo 'PASS all accounts/roles/hashes/timestamps, business data and existing fee rows unchanged'.PHP_EOL;
    } elseif ($action==='audit') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
        echo 'SUPPLIER_TABLES='.json_encode(array_values(array_filter(array_keys($state),fn($t)=>str_contains(strtolower($t),'supplier') || str_contains(strtolower($t),'pemasok')))).PHP_EOL;
        echo 'SUPPLIER_COLUMNS='.json_encode($db->select("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME LIKE '%supplier%' OR COLUMN_NAME LIKE '%pemasok%')")).PHP_EOL;
        echo 'SUPPLIER_FOREIGN_KEYS='.json_encode($db->select("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN ('barang','retur_supplier') OR REFERENCED_TABLE_NAME LIKE '%supplier%')")).PHP_EOL;
        echo 'FEE_ROWS='.$state['biaya_admin_brilink']['count'].PHP_EOL;
    } else throw new RuntimeException();
} catch (Throwable $e) { fwrite(STDERR,'STOP: isolated fee audit failed; sensitive details withheld.'.PHP_EOL); exit(1); }
