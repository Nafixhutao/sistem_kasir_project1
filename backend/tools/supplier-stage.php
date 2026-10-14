<?php
// Test-only operations: verify the actual connection before any DB operation.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
$base = dirname(__DIR__); $root = dirname($base, 2);
$baseline = $root.'/supplier-baseline-20261010.json';
$path = 'database/migrations/2026_10_10_000002_create_suppliers_and_link_barang.php';
try {
    TestDatabaseGuard::settings($base);
    $app = require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    $action = $argv[1] ?? 'inspect';
    $snapshot = function (?array $original = null) use ($db): array {
        TestDatabaseGuard::assertActual($db); $state = [];
        $tables = $original ? array_keys($original) : array_map(fn($r) => array_values((array)$r)[0], $db->select('SHOW TABLES'));
        foreach ($tables as $table) {
            if ($table === 'migrations') continue;
            $schema = $db->select('SHOW COLUMNS FROM `'.str_replace('`', '``', $table).'`');
            $columns = $original[$table]['columns'] ?? array_map(fn($c) => $c->Field, $schema);
            $query = $db->table($table)->select($columns);
            foreach ($schema as $c) if ($c->Key === 'PRI') $query->orderBy($c->Field);
            $rows = $query->get();
            $state[$table] = ['columns'=>$columns, 'count'=>$rows->count(), 'digest'=>hash('sha256', json_encode($rows))];
        }
        ksort($state); return $state;
    };
    if ($action === 'prepare') {
        if (is_file($baseline) || \Illuminate\Support\Facades\Schema::hasTable('suppliers') || \Illuminate\Support\Facades\Schema::hasColumn('barang', 'supplier_id')) throw new RuntimeException();
        $state = $snapshot();
        $file = $root.'/sistem_project1_test_before_supplier_'.date('Ymd_His').'.sql';
        $c = config('database.connections.mysql'); $env = getenv(); $env['MYSQL_PWD'] = $c['password'];
        TestDatabaseGuard::assertActual($db);
        $p = proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe', '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], '--single-transaction', '--skip-lock-tables', '--skip-add-drop-table', '--no-tablespaces', '--set-gtid-purged=OFF', TestDatabaseGuard::DATABASE], [0=>['pipe','r'], 1=>['file',$file,'x'], 2=>['pipe','w']], $pipes, null, $env);
        unset($env); if (!is_resource($p)) throw new RuntimeException();
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p) !== 0 || filesize($file) === 0) throw new RuntimeException();
        $sql = file_get_contents($file);
        foreach ($db->select('SHOW TABLES') as $r) if (!str_contains($sql, 'CREATE TABLE `'.array_values((array)$r)[0].'`')) throw new RuntimeException();
        unset($sql); if ($snapshot() !== $state) throw new RuntimeException();
        $h = fopen($baseline, 'x'); fwrite($h, json_encode($state, JSON_PRETTY_PRINT)); fclose($h);
        $manifest = [];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $manifest[$dir.'/'.substr($entry->getPathname(), strlen($base.'/'.$dir)+1)] = hash_file('sha256', $entry->getPathname());
        ksort($manifest); $h = fopen($root.'/supplier-source-before.json', 'x'); fwrite($h, json_encode($manifest, JSON_PRETTY_PRINT)); fclose($h);
        echo json_encode(['database'=>TestDatabaseGuard::DATABASE,'backup'=>$file,'bytes'=>filesize($file),'users'=>$state['users']['count'],'source_files'=>count($manifest)]).PHP_EOL;
    } elseif ($action === 'compare') {
        $original = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
        if ($snapshot($original) !== $original) throw new RuntimeException();
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers') && ($db->table('suppliers')->count() !== 0 || $db->table('barang')->whereNotNull('supplier_id')->exists())) throw new RuntimeException();
        echo 'PASS original accounts/hashes/timestamps and all original business columns unchanged; no persistent supplier fixtures'.PHP_EOL;
    } elseif (in_array($action, ['pretend','up','down'], true)) {
        TestDatabaseGuard::assertActual($db);
        if (!is_file($baseline)) throw new RuntimeException();
        $original = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
        if ($snapshot($original) !== $original) throw new RuntimeException();
        $migration = require $base.'/'.$path; $migration->preflight($action !== 'down');
        if ($action === 'down') {
            $latest = $db->table('migrations')->orderByDesc('batch')->orderByDesc('migration')->first();
            if (!$latest || $latest->migration !== pathinfo($path, PATHINFO_FILENAME)) throw new RuntimeException();
        }
        $args = ['--path'=>[$path], '--force'=>true];
        if ($action === 'pretend') $args['--pretend'] = true;
        if ($action === 'down') $args['--step'] = 1;
        if (Artisan::call($action === 'down' ? 'migrate:rollback' : 'migrate', $args) !== 0) throw new RuntimeException();
        echo Artisan::output();
    } elseif ($action === 'inspect') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
        echo array_values((array)$db->selectOne('SHOW CREATE TABLE barang'))[1].PHP_EOL;
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) echo array_values((array)$db->selectOne('SHOW CREATE TABLE suppliers'))[1].PHP_EOL;
        echo 'USERS='.$db->table('users')->count().PHP_EOL;
    } elseif ($action === 'manifest') {
        $before = json_decode(file_get_contents($root.'/supplier-source-before.json'), true, 512, JSON_THROW_ON_ERROR);
        $after = [];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $after[$dir.'/'.substr($entry->getPathname(), strlen($base.'/'.$dir)+1)] = hash_file('sha256', $entry->getPathname());
        ksort($after);
        $changes = [];
        foreach ($after as $file=>$digest) if (($before[$file] ?? null) !== $digest) $changes[] = $file;
        foreach ($before as $file=>$digest) if (!isset($after[$file])) throw new RuntimeException('Unexpected source deletion.');
        $h = fopen($root.'/supplier-source-after.json', 'x'); fwrite($h, json_encode($after, JSON_PRETTY_PRINT)); fclose($h);
        echo json_encode(['changed_files'=>$changes], JSON_PRETTY_PRINT).PHP_EOL;
    } else throw new RuntimeException();
} catch (Throwable $e) { fwrite(STDERR, 'STOP: guarded supplier operation failed; sensitive details withheld. No automatic retry.'.PHP_EOL); exit(1); }
