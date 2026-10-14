<?php
// Private-connection TEMPORARY tables shadow accounts/history/tokens only for
// this process. Real users and business tables are never renamed or altered.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Support\AuthTransitionSchema;
use App\Console\Commands\TransitionAuthentication as Transition;

try {
    $base=dirname(__DIR__); $root=dirname($base,2);
    TestDatabaseGuard::$limiterScope=bin2hex(random_bytes(16));
    $app=require $base.'/bootstrap/app.php'; TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection(); TestDatabaseGuard::assertActual($db);
    $check=function(bool $ok,string $step): void { if (!$ok) throw new RuntimeException($step); };
    $snapshot=function() use ($db) {
        TestDatabaseGuard::assertActual($db); $state=[];
        foreach ($db->select('SHOW TABLES') as $r) {
            $t=array_values((array)$r)[0]; $q=$db->table($t);
            foreach ($db->select('SHOW COLUMNS FROM `'.$t.'`') as $c) if ($c->Key==='PRI') $q->orderBy($c->Field);
            $state[$t]=hash('sha256',json_encode($q->get()));
        }
        ksort($state); return $state;
    };
    $before=$snapshot(); echo 'DATABASE=sistem_project1_test'.PHP_EOL;
    $historical=json_decode(file_get_contents($root.'/registry-source-before.json'),true);
    foreach ($historical as $path=>$digest) if (str_starts_with($path,'database/') && str_contains($path,'migrations')) $check(hash_file('sha256',$base.'/'.$path)===$digest,'historical-migration-unchanged');
    // Current approval is TEST-only. Prior read-only legacy metadata is recorded
    // in the audit report; no primary-schema inspection mode remains in this tool.
    $check(in_array($argv[1]??'inspect',['inspect','backup','simulate'],true),'supported-test-only-mode');
    if (($argv[1]??'inspect')==='backup') {
        $file=$root.'/sistem_project1_test_before_auth_transition_'.date('Ymd_His').'.sql';
        $c=config('database.connections.mysql'); $env=getenv(); $env['MYSQL_PWD']=$c['password'];
        TestDatabaseGuard::assertActual($db);
        $p=proc_open(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],'--single-transaction','--skip-lock-tables','--skip-add-drop-table','--no-tablespaces','--set-gtid-purged=OFF',TestDatabaseGuard::DATABASE],[0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']],$pipes,null,$env);
        unset($env); $check(is_resource($p),'backup-start'); fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        $check(proc_close($p)===0 && filesize($file)>0,'backup-complete');
        $sql=file_get_contents($file); foreach (array_keys($before) as $table) $check(str_contains($sql,'CREATE TABLE `'.$table.'`'),'backup-table-coverage'); unset($sql);
        $check($before===$snapshot(),'backup-baseline');
        echo json_encode(['file'=>basename($file),'bytes'=>filesize($file),'database'=>TestDatabaseGuard::DATABASE]).PHP_EOL; exit(0);
    }
    if (($argv[1]??'inspect')!=='simulate') {
        echo 'AUTH_STATE='.AuthTransitionSchema::state().'; USERS='.$db->table('users')->count().PHP_EOL; exit(0);
    }
    $tables=[];
    try {
        TestDatabaseGuard::assertActual($db);
        $db->statement("CREATE TEMPORARY TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(191) NOT NULL, email_verified_at TIMESTAMP NULL DEFAULT NULL, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE KEY users_email_unique(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); $tables[]='users';
        foreach (['personal_access_tokens','migrations'] as $t) {
            $ddl=array_values((array)$db->selectOne('SHOW CREATE TABLE `'.$t.'`'))[1];
            $db->statement(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl)); $tables[]=$t;
        }
        $plain=bin2hex(random_bytes(24));
        $rows=[];
        foreach ([4000000001=>'legacy_admin_fixture',4000000002=>'legacy_second_fixture'] as $id=>$username) $rows[]=['id'=>$id,'name'=>'Legacy fixture','email'=>$username,'email_verified_at'=>null,'password'=>Hash::make($plain),'remember_token'=>bin2hex(random_bytes(12)),'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-02 00:00:00'];
        $db->table('users')->insert($rows);
        $identity=function() use ($db) {
            $out=[];
            foreach ($db->table('users')->orderBy('id')->get() as $r) $out[]=['id'=>$r->id,'name'=>$r->name,'login'=>$r->username??$r->email,'hash'=>$r->password,'remember'=>$r->remember_token,'created'=>$r->created_at,'updated'=>$r->updated_at];
            return $out;
        };
        $legacy=$identity(); $check(AuthTransitionSchema::state()==='legacy','legacy-schema');
        $legacyLookup=$db->table('users')->where('email','legacy_admin_fixture')->first();
        $check($legacyLookup!==null && Hash::check($plain,$legacyLookup->password),'legacy-source-lookup-contract');
        $stage=function(string $stage,bool $verified=false,int $expected=0) use ($db,$check) {
            TestDatabaseGuard::assertActual($db);
            $args=['--stage'=>$stage]; if ($verified) $args['--verified-login']=true;
            $code=Artisan::call('auth:transition',$args); Artisan::output(); // Never print nested migration output or fixture responses.
            $check($code===$expected,'stage-'.$stage);
        };
        $stage('prepare'); $check(AuthTransitionSchema::state()==='prepared','prepared-schema');
        $check($legacy===$identity(),'prepare-account-invariants');
        $check($db->table('users')->where('role','kasir')->where('is_active',true)->count()===2,'legacy-default-kasir-active');
        $check(!$db->table('migrations')->where('migration',Transition::EMAIL)->exists(),'email-not-marked-before-finalization');
        foreach ($db->table('users')->get() as $r) $check($r->email===$r->username && Hash::check($plain,$r->password),'legacy-login-copy-and-password');
        $legacyLookup=$db->table('users')->where('email','legacy_admin_fixture')->first();
        $check($legacyLookup!==null && Hash::check($plain,$legacyLookup->password),'legacy-source-prepared-lookup-contract');
        // Verify rollback refuses losing role/status information before any DDL.
        foreach ([['role'=>'admin'],['is_active'=>false]] as $unsafe) {
            TestDatabaseGuard::assertActual($db); $db->beginTransaction();
            try {
                $db->table('users')->where('id',4000000002)->update($unsafe);
                $refused=false;
                try { AuthTransitionSchema::undoPrepare(); } catch (RuntimeException $e) { $refused=true; }
                $check($refused && AuthTransitionSchema::state()==='prepared','rollback-authorization-loss-refused');
            } finally { $db->rollBack(); }
        }
        echo 'PASS legacy email lookup remains valid before/after prepare; unsafe role/status rollback refused before DDL'.PHP_EOL;
        // Real prepared/final controller contracts, not a replacement auth implementation.
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class); $serial=0;
        $call=function(string $method,string $url,array $data=[],?string $token=null) use ($app,$kernel,&$serial) {
            $app['auth']->forgetGuards(); $serial++;
            $server=['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'198.18.120.'.($serial%240+1)];
            if ($token!==null) $server['HTTP_AUTHORIZATION']='Bearer '.$token;
            $request=Illuminate\Http\Request::create($url,$method,$data,[],[],$server);
            $response=$kernel->handle($request); $result=[$response->getStatusCode(),json_decode($response->getContent(),true)];
            $kernel->terminate($request,$response); return $result;
        };
        $contract=function(string $schema) use ($db,$check,$call,$plain,$legacy,$identity) {
            TestDatabaseGuard::assertActual($db); $db->beginTransaction();
            try {
                $db->table('users')->where('id',4000000002)->update(['role'=>'admin']); // Explicit TEST-only owner assignment.
                [$code,$login]=$call('POST','/api/login',['username'=>'legacy_admin_fixture','password'=>$plain]); $check($code===200,'cashier-login-'.$schema); $token=$login['token'];
                $keys=array_keys($login['user']); sort($keys); $check($keys===['id','name','role','username'],'login-allowlist');
                [$code,$me]=$call('GET','/api/me',[],$token); $check($code===200 && $me['role']==='kasir','me-'.$schema);
                $check($call('GET','/api/users',[],$token)[0]===403,'cashier-management-403');
                $check($call('GET','/api/me')[0]===401,'anonymous-401');
                [$code,$owner]=$call('POST','/api/login',['username'=>'legacy_second_fixture','password'=>$plain]); $check($code===200,'admin-login-'.$schema); $ownerToken=$owner['token'];
                $check($call('GET','/api/users',[],$ownerToken)[0]===200,'admin-management');
                $check($call('POST','/api/penjualan',[],$ownerToken)[0]===403,'admin-business-403');
                $new=bin2hex(random_bytes(24)); $payload=['name'=>'Temporary transition cashier','username'=>'transition_new_fixture','password'=>$new,'password_confirmation'=>$new];
                [$code,$cashier]=$call('POST','/api/users',$payload,$ownerToken); $check($code===201 && $cashier['role']==='kasir','create-cashier-'.$schema);
                $check($call('POST','/api/users',$payload+['role'=>'admin'],$ownerToken)[0]===422,'role-payload-rejected');
                $check($call('POST','/api/users',$payload,$ownerToken)[0]===422,'unique-username');
                [$code,$newLogin]=$call('POST','/api/login',['username'=>$payload['username'],'password'=>$new]); $check($code===200,'new-cashier-login');
                $check($call('PATCH','/api/users/'.$cashier['id'].'/status',['is_active'=>false],$ownerToken)[0]===200,'deactivate');
                $check($call('POST','/api/login',['username'=>$payload['username'],'password'=>$new])[0]===401,'inactive-login');
                $check($call('GET','/api/me',[],$newLogin['token'])[0]===401,'inactive-old-token');
                $check($call('PATCH','/api/users/'.$cashier['id'].'/status',['is_active'=>true],$ownerToken)[0]===200,'activate');
                $check($call('POST','/api/logout',[],$ownerToken)[0]===200,'logout');
                $check($call('GET','/api/me',[],$ownerToken)[0]===401,'logout-revocation');
                $replacement=bin2hex(random_bytes(24));
                $check($call('POST','/api/change-password',['current_password'=>$plain,'password'=>$replacement,'password_confirmation'=>$replacement],$token)[0]===200,'change-password-'.$schema);
                $check($call('GET','/api/me',[],$token)[0]===401,'change-revocation');
                $check($call('POST','/api/login',['username'=>'legacy_admin_fixture','password'=>$plain])[0]===401,'old-password-rejected');
                $check($call('POST','/api/login',['username'=>'legacy_admin_fixture','password'=>$replacement])[0]===200,'new-password-accepted');
            } finally { $db->rollBack(); }
            $check($legacy===$identity(),'contract-rollback-invariants');
            echo 'PASS '.$schema.' actual login/me/logout/change-password, active/inactive, Admin/Kasir 401/403, management, unique username; fixture transaction rolled back'.PHP_EOL;
        };
        $contract('prepared-with-email');
        $stage('finalize',false,1); $check(AuthTransitionSchema::state()==='prepared','finalize-confirmation-gate');
        $stage('finalize',true); $check(AuthTransitionSchema::state()==='final' && $legacy===$identity(),'final-invariants');
        $check($db->table('migrations')->whereIn('migration',[Transition::ROLE,Transition::RENAME,Transition::EMAIL,Transition::USERNAME])->count()===4,'verified-equivalent-history');
        $contract('final-without-email');
        $stage('rollback-finalize'); $check(AuthTransitionSchema::state()==='prepared' && $legacy===$identity(),'rollback-finalize-invariants');
        $check($db->table('users')->whereNotNull('email_verified_at')->count()===0,'rollback-verification-null');
        $stage('rollback-prepare'); $check(AuthTransitionSchema::state()==='username' && $legacy===$identity(),'rollback-prepare-invariants');
        $username=require $base.'/database/migrations/'.Transition::USERNAME.'.php'; $username->down();
        $db->table('migrations')->where('migration',Transition::USERNAME)->delete();
        $check(AuthTransitionSchema::state()==='legacy' && $legacy===$identity(),'rollback-username-invariants');
        $stage('prepare'); $stage('finalize',true); $check($legacy===$identity(),'reapply-invariants');
        $contract('reapplied-final');
        echo 'PASS legacy -> username -> role/status with legacy retained -> final; ID/name/login/hash/timestamps identical; rollback/re-apply and migration history checkpoints'.PHP_EOL;
        foreach ([Transition::PREPARE,Transition::FINALIZE] as $name) {
            $m=require $base.'/database/auth-transitions/'.$name.'.php';
            $sql=$db->pretend(fn()=>$m->up()); $check(count($sql)>0,'migration-pretend');
        }
        echo 'PASS pretend explicit transition migrations'.PHP_EOL;
    } finally {
        while ($db->transactionLevel()>0) $db->rollBack();
        // Closing this private connection releases its TEMPORARY tables in MySQL.
        // No manual DROP TABLE statement is needed; the next snapshot reconnects.
        if ($tables!==[]) $db->disconnect();
        Illuminate\Support\Facades\Cache::store('auth_limiter')->flush();
        unset($plain,$rows,$legacy,$login,$owner,$token);
    }
    $check($before===$snapshot(),'persistent-baseline');
    echo 'PASS every real account/hash/role/status/timestamp and business/registry/history row unchanged; private temporary tables removed'.PHP_EOL;
} catch (Throwable $e) {
    $safe=get_class($e);
    if ($e instanceof \Illuminate\Database\QueryException) {
        preg_match('/^\s*([a-z]+)(?:\s+(?:temporary\s+)?table\s+`?([a-z_]+))?/i',$e->getSql(),$m);
        $safe.='; SQLSTATE='.($e->errorInfo[0]??'unknown').'; code='.(int)($e->errorInfo[1]??0).'; verb='.($m[1]??'unknown').'; table='.($m[2]??'withheld');
    } elseif ($e instanceof \RuntimeException && preg_match('/^[a-z0-9-]+$/D',$e->getMessage())) $safe.='; check='.$e->getMessage();
    fwrite(STDERR,'STOP transition verifier: '.$safe.'; sensitive details withheld.'.PHP_EOL); exit(1);
}
