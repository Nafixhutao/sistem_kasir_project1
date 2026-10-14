<?php
// Isolated test audit only. Never connect to the active application/database.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
$base = dirname(__DIR__); $root = dirname($base, 2);
$baseline = $root.'/restore-baseline-20261010.json';
try {
    TestDatabaseGuard::settings($base);
    $app = require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql'); TestDatabaseGuard::assertActual($db);
    $snapshot = function () use ($db): array {
        TestDatabaseGuard::assertActual($db); $state = [];
        foreach ($db->select('SHOW TABLES') as $r) {
            $table = array_values((array)$r)[0];
            $schema = $db->select('SHOW COLUMNS FROM `'.str_replace('`', '``', $table).'`');
            $query = $db->table($table);
            foreach ($schema as $c) if ($c->Key === 'PRI') $query->orderBy($c->Field);
            $rows = $query->get();
            $state[$table] = ['count'=>$rows->count(), 'digest'=>hash('sha256', json_encode($rows))];
        }
        ksort($state); return $state;
    };
    $action = $argv[1] ?? 'inspect';
    if ($action === 'inspect') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
        echo json_encode($db->select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'), JSON_PRETTY_PRINT).PHP_EOL;
        echo json_encode($db->select('SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'), JSON_PRETTY_PRINT).PHP_EOL;
    } elseif ($action === 'prepare') {
        if (is_file($baseline)) throw new RuntimeException();
        $state = $snapshot();
        $file = $root.'/sistem_project1_test_before_atomic_restore_'.date('Ymd_His').'.sql';
        $c = config('database.connections.mysql'); $env = getenv(); $env['MYSQL_PWD'] = $c['password'];
        TestDatabaseGuard::assertActual($db);
        $p = proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe', '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], '--single-transaction', '--skip-lock-tables', '--skip-add-drop-table', '--no-tablespaces', '--set-gtid-purged=OFF', TestDatabaseGuard::DATABASE], [0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']], $pipes, null, $env);
        unset($env); if (!is_resource($p)) throw new RuntimeException();
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p) !== 0 || filesize($file) === 0) throw new RuntimeException();
        $sql = file_get_contents($file);
        foreach (array_keys($state) as $table) if (!str_contains($sql, 'CREATE TABLE `'.$table.'`')) throw new RuntimeException();
        unset($sql); if ($snapshot() !== $state) throw new RuntimeException();
        $h = fopen($baseline, 'x'); fwrite($h, json_encode($state, JSON_PRETTY_PRINT)); fclose($h);
        $manifest = [];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $manifest[$dir.'/'.substr($entry->getPathname(), strlen($base.'/'.$dir)+1)] = hash_file('sha256', $entry->getPathname());
        ksort($manifest); $h = fopen($root.'/restore-source-before.json', 'x'); fwrite($h, json_encode($manifest, JSON_PRETTY_PRINT)); fclose($h);
        echo json_encode(['database'=>TestDatabaseGuard::DATABASE,'backup'=>$file,'bytes'=>filesize($file),'users'=>$state['users']['count']]).PHP_EOL;
    } elseif ($action === 'manifest') {
        $before = json_decode(file_get_contents($root.'/restore-source-before.json'), true, 512, JSON_THROW_ON_ERROR);
        $after = [];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $after[$dir.'/'.substr($entry->getPathname(), strlen($base.'/'.$dir)+1)] = hash_file('sha256', $entry->getPathname());
        ksort($after); $changes = [];
        foreach ($after as $file=>$digest) if (($before[$file] ?? null) !== $digest) $changes[] = $file;
        foreach ($before as $file=>$digest) if (!isset($after[$file])) throw new RuntimeException();
        $h = fopen($root.'/restore-source-after.json', 'x'); fwrite($h, json_encode($after, JSON_PRETTY_PRINT)); fclose($h);
        echo json_encode(['changed_files'=>$changes], JSON_PRETTY_PRINT).PHP_EOL;
    } elseif ($action === 'tests') {
        TestDatabaseGuard::assertActual($db);
        $p = proc_open([PHP_BINARY,$base.'/vendor/phpunit/phpunit/phpunit','--configuration',$base.'/phpunit.xml','--filter','TransactionalBackupRestoreTest|test_backup_allowlist_excludes_accounts'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $base);
        fclose($pipes[0]); $output=stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($p);
        foreach (explode("\n", $output) as $line) {
            // Only locations/summary/assertion statuses, never response data or bindings.
            if (preg_match('/^(PHPUnit |Time: |OK \(|Tests: |FAILURES!|ERRORS!|\d+\) Tests\\\\|Expected response status code|Failed asserting|Error:)/', trim($line)) || preg_match('~TransactionalBackupRestoreTest.php:[0-9]+$~', trim($line))) echo trim($line).PHP_EOL;
        }
        if ($code !== 0) exit(1);
    } elseif ($action === 'compare') {
        $original = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR);
        $current = $snapshot();
        if ($current['migrations'] !== $original['migrations']) {
            $backups = glob($root.'/sistem_project1_test_before_atomic_restore_*.sql');
            if (count($backups) !== 1) throw new RuntimeException();
            $rows = [];
            $h = fopen($backups[0], 'r');
            while (($line = fgets($h)) !== false) {
                if (!str_starts_with($line, 'INSERT INTO `migrations` VALUES ')) continue;
                preg_match_all("/\((\d+),'([a-zA-Z0-9_]+)',(\d+)\)/", $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) $rows[$m[2]] = ['id'=>(int)$m[1],'batch'=>(int)$m[3]];
            }
            fclose($h);
            $actual = [];
            foreach ($db->table('migrations')->get() as $r) $actual[$r->migration] = ['id'=>(int)$r->id,'batch'=>(int)$r->batch];
            $target = '2026_10_10_000002_create_suppliers_and_link_barang';
            if (!isset($rows[$target], $actual[$target]) || $rows[$target]['batch'] !== $actual[$target]['batch']) throw new RuntimeException();
            unset($rows[$target], $actual[$target]); ksort($rows); ksort($actual);
            if ($rows !== $actual || $current['migrations']['count'] !== $original['migrations']['count']) throw new RuntimeException();
            echo 'PASS migration history unchanged except expected new Supplier migration record ID after down/up'.PHP_EOL;
        }
        unset($current['migrations'], $original['migrations']);
        if ($current !== $original) throw new RuntimeException();
        echo 'PASS all existing rows/accounts/hashes/timestamps and business data unchanged'.PHP_EOL;
    } else throw new RuntimeException();
} catch (Throwable $e) { fwrite(STDERR, 'STOP: isolated restore audit failed; sensitive details withheld.'.PHP_EOL); exit(1); }
