<?php
namespace App\Console\Commands;

use App\Support\AuthTransitionSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransitionAuthentication extends Command
{
    protected $signature='auth:transition {--stage=inspect : inspect, prepare, finalize, rollback-finalize, rollback-prepare} {--verified-login : Explicit confirmation of username login on prepared schema}';
    protected $description='Staged authentication transition candidate; currently restricted to the test database';
    public const PREPARE='2026_10_11_000002_prepare_users_auth_transition';
    public const FINALIZE='2026_10_11_000003_finalize_users_auth_transition';
    public const ROLE='2026_10_08_000002_add_role_and_active_status_to_users';
    public const RENAME='2026_10_10_000001_rename_owner_role_to_admin';
    public const EMAIL='2026_10_08_000001_remove_legacy_email_from_users';
    public const USERNAME='2026_09_13_000001_add_username_to_users_safely';

    private function guard(): void
    {
        $c=config('database.connections.'.config('database.default'));
        if (($c['driver']??'')!=='mysql' || ($c['database']??'')!=='sistem_project1_test' || !empty($c['url'])
            || DB::selectOne('SELECT DATABASE() AS db')->db!=='sistem_project1_test') throw new RuntimeException('Test-only candidate: actual database rejected.');
    }

    private function migrate(string $name,bool $transition): void
    {
        $this->guard();
        $path='database/'.($transition?'auth-transitions':'migrations').'/'.$name.'.php';
        if (Artisan::call('migrate',['--path'=>[$path],'--force'=>true])!==0) throw new RuntimeException('Migration stopped.');
    }

    private function equivalents(string $bridge,array $names): void
    {
        $this->guard();
        $batch=DB::table('migrations')->where('migration',$bridge)->value('batch');
        if ($batch===null) throw new RuntimeException('Verified bridge history missing.');
        // Explicitly documented equivalents, never pretend the old DDL ran.
        // All required schema/data invariants are verified before this checkpoint.
        DB::transaction(function() use ($batch,$names) {
            foreach ($names as $name) if (!DB::table('migrations')->where('migration',$name)->exists()) DB::table('migrations')->insert(['migration'=>$name,'batch'=>$batch]);
        });
    }

    private function undo(string $bridge,array $equivalents): void
    {
        $this->guard();
        $batch=DB::table('migrations')->where('migration',$bridge)->value('batch');
        if ($batch===null) throw new RuntimeException('Only an applied additive bridge can be reverted.');
        foreach ($equivalents as $name) if (DB::table('migrations')->where('migration',$name)->value('batch')!==$batch) throw new RuntimeException('Cannot remove unrelated historical migration entries.');
        $migration=require database_path('auth-transitions/'.$bridge.'.php');
        $migration->down();
        $this->guard();
        DB::table('migrations')->where('batch',$batch)->whereIn('migration',array_merge([$bridge],$equivalents))->delete();
    }

    public function handle(): int
    {
        try {
            $this->guard(); $stage=$this->option('stage'); $state=AuthTransitionSchema::state();
            if ($stage==='inspect') { $this->info('DATABASE=sistem_project1_test; AUTH_STATE='.$state); return self::SUCCESS; }
            if (!in_array($stage,['prepare','finalize','rollback-finalize','rollback-prepare'],true)) throw new RuntimeException('Invalid stage.');
            if ($stage==='finalize' && !$this->option('verified-login')) { $this->error('Finalisasi memerlukan konfirmasi login username pada schema transisi.'); return self::FAILURE; }
            $before=DB::table('users')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
            if ($stage==='prepare') {
                if ($state==='legacy') { $this->migrate(self::USERNAME,false); $state=AuthTransitionSchema::state(); }
                if ($state==='username') { $this->migrate(self::PREPARE,true); $state=AuthTransitionSchema::state(); }
                if ($state==='prepared') $this->equivalents(self::PREPARE,[self::ROLE,self::RENAME]);
                elseif ($state!=='final') throw new RuntimeException('Prepare failed.');
            } elseif ($stage==='finalize') {
                if ($state==='prepared') { $this->migrate(self::FINALIZE,true); $state=AuthTransitionSchema::state(); }
                if ($state!=='final') throw new RuntimeException('Final schema not verified.');
                if (DB::table('migrations')->where('migration',self::FINALIZE)->exists()) $this->equivalents(self::FINALIZE,[self::EMAIL]);
            } elseif ($stage==='rollback-finalize') $this->undo(self::FINALIZE,[self::EMAIL]);
            else $this->undo(self::PREPARE,[self::ROLE,self::RENAME]);
            $after=DB::table('users')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
            if (count($before)!==count($after)) throw new RuntimeException('Account count changed.');
            foreach ($before as $i=>$row) {
                foreach (['id','name','password','remember_token','created_at','updated_at'] as $field) if ($row[$field]!==$after[$i][$field]) throw new RuntimeException('Account invariant changed.');
                if (($row['username']??$row['email'])!==($after[$i]['username']??$after[$i]['email'])) throw new RuntimeException('Login identity changed.');
            }
            $this->info('PASS stage='.$stage.'; AUTH_STATE='.AuthTransitionSchema::state().'; account identity/hash/timestamps unchanged.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('STOP: transisi gagal. Periksa keadaan aktual; jangan retry/rollback otomatis. Detail sensitif tidak ditampilkan.');
            return self::FAILURE;
        }
    }
}
