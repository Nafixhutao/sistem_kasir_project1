<?php

// Isolated test harness. Never boots or connects to the active project.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

$base = dirname(__DIR__);
$root = dirname($base, 2);
$baselineFile = $root.'/stage2-test-baseline.json';
$migration = 'database/migrations/2026_10_08_000001_remove_legacy_email_from_users.php';

function snapshot($db): array
{
    TestDatabaseGuard::assertActual($db);
    $state = ['users_count' => $db->table('users')->count(), 'tables' => []];
    $state['users_digest'] = hash('sha256', json_encode($db->table('users')->orderBy('id')
        ->get(['id', 'name', 'username', 'password', 'remember_token', 'created_at', 'updated_at'])));
    foreach ($db->select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];
        if (in_array($table, ['users', 'migrations'], true)) continue;
        $query = $db->table($table);
        foreach ($db->select('SHOW COLUMNS FROM `'.str_replace('`', '``', $table).'`') as $column) {
            if ($column->Key === 'PRI') $query->orderBy($column->Field);
        }
        $rows = $query->get();
        $state['tables'][$table] = ['count' => $rows->count(), 'digest' => hash('sha256', json_encode($rows))];
    }
    ksort($state['tables']);
    return $state;
}

try {
    $settings = TestDatabaseGuard::settings($base);
    $app = require $base.'/bootstrap/app.php';
    TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql');
    TestDatabaseGuard::assertActual($db);
    $action = $argv[1] ?? 'inspect';
    if ($action === 'manifest') {
        $manifest = [];
        foreach (['app','config','database','routes','tests','tools'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) if ($file->isFile()) {
                $manifest[str_replace('\\', '/', substr($file->getPathname(), strlen($base)+1))] = hash_file('sha256', $file->getPathname());
            }
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($base).'/frontend/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) if ($file->isFile()) $manifest['frontend/'.str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($base).'/frontend/') ))] = hash_file('sha256', $file->getPathname());
        ksort($manifest);
        $phase = $argv[2] ?? 'before';
        if (!in_array($phase, ['before','after','password-before','password-after','password-final'], true)) throw new RuntimeException('Invalid manifest phase.');
        $file = $root.'/stage2-source-'.$phase.'.json';
        if (file_exists($file)) throw new RuntimeException('Manifest already exists.');
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT));
        echo 'PASS source manifest '.count($manifest).' files'."\n";
    } elseif ($action === 'inspect') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE."\n";
        echo 'USERS='.$db->table('users')->count()."\n";
        echo 'ADMIN='.$db->table('users')->where('username', 'admin')->count()."\n";
        echo array_values((array) $db->selectOne('SHOW CREATE TABLE users'))[1]."\n";
        echo json_encode($db->table('migrations')->where('migration', 'like', '%username%')
            ->orWhere('migration', 'like', '%legacy_email%')->get(['migration', 'batch']))."\n";
    } elseif ($action === 'backup') {
        $columns = array_column($db->select('SHOW COLUMNS FROM users'), 'Field');
        if ($columns !== ['id','name','username','email','email_verified_at','password','remember_token','created_at','updated_at']
            || $db->table('migrations')->where('migration', '2026_09_13_000001_add_username_to_users_safely')->count() !== 1
            || $db->table('users')->where('username', 'admin')->count() !== 1) {
            throw new RuntimeException('Unexpected transition schema/migration/accounts.');
        }
        $state = snapshot($db);
        $file = $root.'/sistem_project1_test_before_email_removal_'.date('Ymd_His').'.sql';
        $dump = 'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe';
        if (!is_file($dump) || file_exists($file) || file_exists($baselineFile)) throw new RuntimeException('Backup target/tool unavailable.');
        $c = config('database.connections.mysql');
        $env = getenv(); $env['MYSQL_PWD'] = $c['password'];
        TestDatabaseGuard::assertActual($db);
        $process = proc_open([$dump, '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            '--single-transaction', '--skip-lock-tables', '--skip-add-drop-table', '--no-tablespaces',
            '--set-gtid-purged=OFF', TestDatabaseGuard::DATABASE],
            [0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']], $pipes, null, $env);
        unset($env);
        if (!is_resource($process)) throw new RuntimeException('Backup process failed.');
        fclose($pipes[0]); $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || filesize($file) === 0) throw new RuntimeException('Backup failed.');
        unset($errors);
        $sql = file_get_contents($file);
        foreach ($db->select('SHOW TABLES') as $row) {
            if (!str_contains($sql, 'CREATE TABLE `'.array_values((array) $row)[0].'`')) throw new RuntimeException('Incomplete dump.');
        }
        unset($sql);
        if ($state !== snapshot($db)) throw new RuntimeException('Data changed during backup.');
        $state['backup'] = ['file'=>$file,'bytes'=>filesize($file),'created_at'=>date(DATE_ATOM),'database'=>TestDatabaseGuard::DATABASE];
        file_put_contents($baselineFile, json_encode($state, JSON_PRETTY_PRINT));
        echo json_encode($state['backup'])."\nPASS backup and private baseline\n";
    } elseif ($action === 'tests') {
        TestDatabaseGuard::assertActual($db);
        $process = proc_open([PHP_BINARY, $base.'/vendor/phpunit/phpunit/phpunit', '--configuration', $base.'/phpunit.xml'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $base);
        if (!is_resource($process)) throw new RuntimeException('Cannot start PHPUnit.');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        // Do not print failure payloads, stack arguments, tokens or hashes.
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(PHPUnit |Time: |OK \(|Tests: |FAILURES!|ERRORS!)/', trim($line))) echo trim($line)."\n";
        }
        if ($code !== 0) {
            preg_match_all('/^\d+\) ([A-Za-z0-9_\\\\]+::[A-Za-z0-9_]+)/m', $output, $matches);
            foreach ($matches[1] as $test) echo 'FAILED TEST: '.$test."\n";
            unset($output, $errors);
            throw new RuntimeException('PHPUnit failed; sensitive output suppressed.');
        }
        unset($output, $errors);
        echo "PASS full PHPUnit suite\n";
    } elseif ($action === 'preflight') {
        TestDatabaseGuard::assertActual($db);
        (require $base.'/'.$migration)->preflight(true);
        echo "PASS real-data preflight before DDL\n";
    } elseif ($action === 'restored') {
        TestDatabaseGuard::assertActual($db);
        (require $base.'/'.$migration)->preflight(true);
        if ($db->table('users')->whereNotNull('email_verified_at')->exists()) throw new RuntimeException('Unexpected restored verification timestamp.');
        echo "PASS email copied exactly, NOT NULL unique index restored, verification timestamps NULL\n";
    } elseif ($action === 'compare') {
        $baseline = json_decode(file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR);
        unset($baseline['backup']);
        if ($baseline !== snapshot($db)) throw new RuntimeException('Account/business baseline mismatch. STOP.');
        echo "PASS all accounts, hashes, timestamps and existing table contents unchanged\n";
    } elseif (in_array($action, ['pretend','up','down'], true)) {
        if (!is_file($baselineFile)) throw new RuntimeException('Backup/baseline required.');
        TestDatabaseGuard::assertActual($db);
        $baseline = json_decode(file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR);
        unset($baseline['backup']);
        if ($baseline !== snapshot($db)) throw new RuntimeException('Baseline mismatch before migration. STOP.');
        if ($action === 'down') {
            $latest = $db->table('migrations')->orderByDesc('batch')->orderByDesc('migration')->first();
            if (!$latest || $latest->migration !== pathinfo($migration, PATHINFO_FILENAME)) throw new RuntimeException('Target is not latest migration.');
            $code = Artisan::call('migrate:rollback', ['--path'=>[$migration], '--step'=>1, '--force'=>true]);
        } else {
            $code = Artisan::call('migrate', ['--path'=>[$migration], '--force'=>true, '--pretend'=>$action==='pretend']);
        }
        if ($code !== 0) throw new RuntimeException('Migration command failed. STOP.');
        echo Artisan::output();
    } else throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    echo 'FAILED: '.get_class($e).' (details withheld; stop and inspect)' . "\n";
    exit(1);
}
