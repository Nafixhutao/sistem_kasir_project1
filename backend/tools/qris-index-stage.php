<?php
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
try {
    $base=dirname(__DIR__); $root=dirname($base,2);
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db);
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    echo array_values((array)$db->selectOne('SHOW CREATE TABLE pembayaran_qris'))[1].PHP_EOL;
    echo json_encode($db->select('SHOW INDEX FROM pembayaran_qris')).PHP_EOL;
    echo json_encode($db->table('migrations')->where('migration','like','%idempot%')->orWhere('migration','like','%qris%')->get(['migration','batch'])).PHP_EOL;
    $schema=[];
    foreach ($db->select('SHOW TABLES') as $r) {
        $t=array_values((array)$r)[0];
        $columns=[]; foreach ($db->select('SHOW COLUMNS FROM `'.$t.'`') as $c) $columns[$c->Field]=[$c->Type,$c->Null,$c->Default,$c->Extra];
        $indexes=[]; foreach ($db->select('SHOW INDEX FROM `'.$t.'`') as $i) $indexes[$i->Key_name][] = [$i->Column_name,(int)$i->Non_unique,$i->Sub_part];
        $schema[$t]=['columns'=>$columns,'indexes'=>$indexes];
        echo 'SCHEMA='.json_encode([$t=>$schema[$t]]).PHP_EOL;
    }
    echo 'FOREIGN_KEYS='.json_encode($db->select("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")).PHP_EOL;
    foreach (glob($root.'/sistem_project1_test_before_*.sql') as $file) {
        $flags=['sale_index'=>false,'transaction_index'=>false,'missing_migration_history'=>false];
        $h=fopen($file,'r');
        while (($line=fgets($h))!==false) {
            if (str_contains($line,'UNIQUE KEY `pembayaran_qris_sale_unique`')) $flags['sale_index']=true;
            if (str_contains($line,'UNIQUE KEY `pembayaran_qris_transaction_id_unique`')) $flags['transaction_index']=true;
            if (str_starts_with($line,'INSERT INTO `migrations` VALUES ') && str_contains($line,'2026_08_10_000002_harden_transaction_idempotency')) $flags['missing_migration_history']=true;
        }
        fclose($h); echo json_encode(['backup'=>basename($file)]+$flags).PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'STOP QRIS audit; sensitive details withheld.'.PHP_EOL); exit(1); }
