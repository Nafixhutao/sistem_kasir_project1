<?php
// Guarded harness for this isolated test copy only. Never accepts a database argument.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
$base = dirname(__DIR__);
$root = dirname($base, 2);
$path = 'database/migrations/2026_10_08_000002_add_role_and_active_status_to_users.php';
try {
    TestDatabaseGuard::settings($base);
    $app = require $base.'/bootstrap/app.php';
    TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('mysql');
    TestDatabaseGuard::assertActual($db);
    $action = $argv[1] ?? 'inspect';
    if ($action === 'backup') {
        if (array_column($db->select('SHOW COLUMNS FROM users'), 'Field') !== ['id','name','username','password','remember_token','created_at','updated_at']) throw new RuntimeException('Unexpected schema.');
        $file = $root.'/sistem_project1_test_before_roles_'.date('Ymd_His').'.sql';
        $config = config('database.connections.mysql');
        $env = getenv(); $env['MYSQL_PWD'] = $config['password'];
        TestDatabaseGuard::assertActual($db);
        $process = proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--single-transaction', '--skip-lock-tables', '--skip-add-drop-table', '--no-tablespaces', '--set-gtid-purged=OFF', TestDatabaseGuard::DATABASE], [0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']], $pipes, null, $env);
        unset($env);
        if (!is_resource($process)) throw new RuntimeException('Backup unavailable.');
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || filesize($file) === 0) throw new RuntimeException('Backup failed.');
        $dump = file_get_contents($file);
        foreach ($db->select('SHOW TABLES') as $row) if (!str_contains($dump, 'CREATE TABLE `'.array_values((array)$row)[0].'`')) throw new RuntimeException('Incomplete dump.');
        unset($dump);
        echo json_encode(['database'=>TestDatabaseGuard::DATABASE,'backup'=>$file,'bytes'=>filesize($file),'created_at'=>date(DATE_ATOM)]).PHP_EOL;
    } elseif ($action === 'manifest') {
        $phase = $argv[2] ?? '';
        if (!in_array($phase, ['before','after'], true)) throw new RuntimeException('Invalid phase.');
        $file = $root.'/role-source-'.$phase.'.json';
        if (file_exists($file)) throw new RuntimeException('Manifest already exists.');
        $manifest = [];
        foreach (['app','config','database','routes','tests','tools','../frontend/src'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $manifest[$dir.'/'.substr($entry->getPathname(), strlen($base.'/'.$dir)+1)] = hash_file('sha256', $entry->getPathname());
        }
        ksort($manifest); file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT));
        echo 'PASS manifest '.count($manifest).' files'.PHP_EOL;
    } elseif (in_array($action, ['pretend','up','down'], true)) {
        TestDatabaseGuard::assertActual($db);
        $command = $action === 'down' ? 'migrate:rollback' : 'migrate';
        $args = ['--path'=>$path,'--force'=>true];
        if ($action === 'pretend') {
            $migration = require $base.'/'.$path;
            $migration->validateUp();
            $args['--pretend'] = true;
        }
        $result = Artisan::call($command, $args);
        if ($result !== 0) throw new RuntimeException('Migration failed.');
        echo Artisan::output();
    } elseif ($action === 'inspect') {
        echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
        echo array_values((array)$db->selectOne('SHOW CREATE TABLE users'))[1].PHP_EOL;
        echo 'USERS='.$db->table('users')->count().PHP_EOL;
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'role')) echo 'EXISTING_ACTIVE_CASHIERS='.$db->table('users')->where('role','kasir')->where('is_active',true)->count().PHP_EOL;
    } else throw new RuntimeException('Unknown action.');
} catch (Throwable $e) { fwrite(STDERR, 'STOP: guarded role operation failed; inspect before retry.'.PHP_EOL); exit(1); }
