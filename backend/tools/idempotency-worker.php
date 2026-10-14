<?php
// Separate process/connection for the test-only race probe. Secrets enter via stdin.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
try {
    \Tests\Support\TestDatabaseGuard::$limiterScope=bin2hex(random_bytes(16));
    $app=require dirname(__DIR__).'/bootstrap/app.php';
    \Tests\Support\TestDatabaseGuard::install($app);
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
    \Tests\Support\TestDatabaseGuard::assertActual(\Illuminate\Support\Facades\DB::connection());
    echo "READY\n"; fflush(STDOUT);
    $input=json_decode(fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
    $delay=max(0,(int)(($input['start']-microtime(true))*1000000));
    if ($delay) usleep($delay);
    \Illuminate\Support\Facades\DB::listen(function ($query) {
        // Force an overlap while the first transaction has uncommitted business effects.
        if (preg_match('/^insert into `(transaksi_penjualan|retur_pelanggan|retur_supplier)`/i',$query->sql)) usleep(300000);
    });
    $request=\Illuminate\Http\Request::create($input['url'],'POST',$input['payload'],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$input['token'],'REMOTE_ADDR'=>'198.18.80.42']);
    unset($input['token']);
    $response=$kernel->handle($request); $body=json_decode($response->getContent(),true);
    echo json_encode(['status'=>$response->getStatusCode(),'id'=>$body['transaksi']['id'] ?? $body['retur']['id'] ?? $body['id'] ?? null]).PHP_EOL;
    $kernel->terminate($request,$response);
    \Illuminate\Support\Facades\Cache::store('auth_limiter')->flush();
} catch (Throwable $e) { fwrite(STDERR,"STOP race worker failed; details withheld.\n"); exit(1); }
