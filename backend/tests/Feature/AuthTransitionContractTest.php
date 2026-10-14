<?php
namespace Tests\Feature;

use App\Support\AuthTransitionSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTransitionContractTest extends TestCase
{
    private function snapshot(): string
    {
        return hash('sha256',json_encode([DB::table('users')->orderBy('id')->get(),DB::table('migrations')->orderBy('id')->get()]));
    }

    public function test_inspect_and_prepare_on_verified_final_schema_are_read_only(): void
    {
        $before=$this->snapshot();
        $this->assertSame('final',AuthTransitionSchema::state());
        $this->assertSame(0,Artisan::call('auth:transition',['--stage'=>'inspect']));
        $this->assertSame(0,Artisan::call('auth:transition',['--stage'=>'prepare']));
        $this->assertSame($before,$this->snapshot());
    }

    public function test_finalization_requires_explicit_login_confirmation(): void
    {
        $before=$this->snapshot();
        $this->assertSame(1,Artisan::call('auth:transition',['--stage'=>'finalize']));
        $this->assertSame(0,Artisan::call('auth:transition',['--stage'=>'finalize','--verified-login'=>true]));
        $this->assertSame($before,$this->snapshot());
    }

    public function test_candidate_rejects_main_configuration_before_database_query(): void
    {
        $before=$this->snapshot(); $queries=0;
        DB::listen(function() use (&$queries) { $queries++; });
        $old=config('database.connections.mysql.database');
        try {
            config(['database.connections.mysql.database'=>'sistem_project1']);
            $this->assertSame(1,Artisan::call('auth:transition',['--stage'=>'prepare']));
            $this->assertSame(0,$queries);
        } finally { config(['database.connections.mysql.database'=>$old]); }
        $this->assertSame($before,$this->snapshot());
    }

    public function test_additive_preparation_refuses_an_already_final_schema_before_ddl(): void
    {
        $ddl=array_values((array)DB::selectOne('SHOW CREATE TABLE users'))[1]; $refused=false;
        try { AuthTransitionSchema::prepare(); } catch (\RuntimeException $e) { $refused=true; }
        $this->assertTrue($refused);
        $this->assertSame($ddl,array_values((array)DB::selectOne('SHOW CREATE TABLE users'))[1]);
    }

    public function test_historical_path_is_not_silently_rolled_back_by_the_new_command(): void
    {
        $before=$this->snapshot();
        $this->assertSame(1,Artisan::call('auth:transition',['--stage'=>'rollback-finalize']));
        $this->assertSame(1,Artisan::call('auth:transition',['--stage'=>'rollback-prepare']));
        $this->assertSame($before,$this->snapshot());
    }
}
