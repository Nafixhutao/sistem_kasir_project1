<?php
ini_set('display_errors','0');ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
try {
    $base=dirname(__DIR__);$app=require $base.'/bootstrap/app.php';TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach(['wrong-index','duplicate-sale','duplicate-transaction-collation','missing-pretend','unsafe-support-rollback'] as $case){
        $db=DB::connection();TestDatabaseGuard::assertActual($db);
        $db->statement('CREATE TEMPORARY TABLE pembayaran_qris (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transaksi_penjualan_id INT UNSIGNED NOT NULL, transaction_id VARCHAR(100) NULL, KEY pembayaran_qris_transaksi_penjualan_id_foreign (transaksi_penjualan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if($case==='wrong-index')$db->statement('ALTER TABLE pembayaran_qris ADD INDEX pembayaran_qris_sale_unique (transaksi_penjualan_id)');
        if($case==='duplicate-sale')$db->table('pembayaran_qris')->insert([['transaksi_penjualan_id'=>1,'transaction_id'=>null],['transaksi_penjualan_id'=>1,'transaction_id'=>null]]);
        if($case==='duplicate-transaction-collation')$db->table('pembayaran_qris')->insert([['transaksi_penjualan_id'=>1,'transaction_id'=>'COLLATION-ID'],['transaksi_penjualan_id'=>2,'transaction_id'=>'collation-id']]);
        $indexes=$db->select('SHOW INDEX FROM pembayaran_qris');
        $migration=require $base.'/database/migrations/2026_10_11_000004_complete_qris_unique_indexes.php';
        if($case==='missing-pretend'){
            $queries=$db->pretend(fn()=>$migration->up());
            if(count($queries)!==1 || !str_contains($queries[0]['query'],'ADD UNIQUE INDEX `pembayaran_qris_sale_unique`') || !str_contains($queries[0]['query'],'ADD UNIQUE INDEX `pembayaran_qris_transaction_id_unique`') || $indexes!=$db->select('SHOW INDEX FROM pembayaran_qris'))throw new RuntimeException('Pretend failed.');
        }elseif($case==='unsafe-support-rollback'){
            $migration->up();
            $db->statement('ALTER TABLE pembayaran_qris DROP INDEX pembayaran_qris_transaksi_penjualan_id_foreign');
            $indexes=$db->select('SHOW INDEX FROM pembayaran_qris');
            try{$migration->down();throw new LogicException('Unsafe rollback accepted.');}catch(RuntimeException $expected){}
            if($indexes!=$db->select('SHOW INDEX FROM pembayaran_qris'))throw new RuntimeException('Partial rollback detected.');
        }else{
            try{$migration->up();throw new LogicException('Invalid preflight accepted.');}catch(RuntimeException $expected){}
            if($indexes!=$db->select('SHOW INDEX FROM pembayaran_qris'))throw new RuntimeException('DDL happened before checks completed.');
        }
        echo 'PASS '.$case.'; no persistent data touched'.PHP_EOL;
        DB::disconnect();
    }
}catch(Throwable $e){fwrite(STDERR,'STOP private QRIS preflight test; sensitive details withheld.'.PHP_EOL);exit(1);}
