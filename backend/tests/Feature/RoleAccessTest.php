<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
class RoleAccessTest extends TestCase
{
    private function fixture(string $role='kasir'): array
    {
        $password = bin2hex(random_bytes(16));
        $user = User::factory()->create(['password'=>Hash::make($password)]);
        $user->role = $role; $user->is_active = true; $user->save();
        return [$user,$password,$user->createToken('role-fixture')->plainTextToken];
    }
    private function asToken(?string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        if ($token) $this->withToken($token);
        return $this;
    }
    private function digest(User $user): string { return hash('sha256',json_encode($user->fresh()->getRawOriginal())); }
    public function test_roles_login_and_user_allowlist(): void
    {
        foreach (['kasir','admin'] as $role) {
            [$user,$password] = $this->fixture($role);
            $res = $this->asToken(null)->postJson('/api/login',['username'=>$user->username,'password'=>$password])->assertOk()->assertJsonPath('user.role',$role);
            $this->assertSame(['id','name','username','role'],array_keys($res->json('user')));
            $this->asToken($res->json('token'))->getJson('/api/me')->assertExactJson($user->only(['id','name','username','role']));
        }
        $this->asToken(null)->getJson('/api/users')->assertUnauthorized();
    }
    public function test_owner_read_routes_have_no_business_writes(): void
    {
        [$owner,,$token] = $this->fixture('admin');
        $before = [];
        foreach (['barang','transaksi_penjualan','transaksi_transfer','pengeluaran_toko','retur_supplier','activity_logs','providers','biaya_admin_brilink'] as $table) $before[$table] = hash('sha256',json_encode(DB::table($table)->orderBy('id')->get()));
        foreach (['dashboard','barang','barang-search','laporan/penjualan','laporan/retur-pelanggan','riwayat-brilink','pengeluaran-toko','retur-supplier','activity-logs','notifikasi','notifikasi/unread-count','users','biaya-admin'] as $path) $this->asToken($token)->getJson('/api/'.$path)->assertOk();
        foreach ($before as $table=>$digest) $this->assertTrue($digest === hash('sha256',json_encode(DB::table($table)->orderBy('id')->get())),'Read route must not change data.');
        $log = ActivityLog::create(['user_name'=>'system','module'=>'Fixture','action'=>'fixture','properties'=>['internal'=>'private']]);
        $res = $this->asToken($token)->getJson('/api/activity-logs?module=Fixture')->assertOk();
        $this->assertArrayNotHasKey('properties',$res->json()[0]);
    }
    public function test_owner_all_existing_operational_routes_are_denied_before_controller(): void
    {
        [,,$token] = $this->fixture('admin');
        $common = ['api/logout','api/change-password','api/me','api/dashboard','api/barang','api/barang/{barang}','api/barang-search','api/laporan/penjualan','api/laporan/retur-pelanggan','api/riwayat-brilink','api/pengeluaran-toko','api/retur-supplier','api/retur-supplier/{retur_supplier}','api/activity-logs','api/notifikasi','api/notifikasi/unread-count','api/notifikasi/{id}/read','api/notifikasi/read-all'];
        foreach ($this->app['router']->getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri,'api/') || in_array($uri,['api/login','api/midtrans/notification'],true) || str_starts_with($uri,'api/users') || str_starts_with($uri,'api/suppliers')) continue;
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') continue;
                if (($uri === 'api/biaya-admin' && $method === 'GET') || ($uri === 'api/biaya-admin/{jenisTransaksi}' && $method === 'PUT')) continue;
                if (in_array($uri,$common,true) && ($method === 'GET' || in_array($uri,['api/logout','api/change-password','api/notifikasi/{id}/read','api/notifikasi/read-all'],true))) continue;
                $path = preg_replace('/\{[^}]+\}/','999999',$uri);
                $this->asToken($token)->json($method,'/'.$path,[])->assertForbidden();
            }
        }
    }
    public function test_cashier_cannot_manage_users_or_assign_role(): void
    {
        [$cashier,,$token] = $this->fixture();
        foreach ([['GET','/api/users'],['POST','/api/users'],['PATCH','/api/users/'.$cashier->id.'/status'],['POST','/api/users/'.$cashier->id.'/reset-password']] as [$method,$path]) $this->asToken($token)->json($method,$path,[])->assertForbidden();
        $cashier->fill(['role'=>'admin','is_active'=>false]);
        $this->assertSame('kasir',$cashier->role); $this->assertTrue($cashier->is_active);
        $this->asToken($token)->postJson('/api/change-password',['role'=>'admin'])->assertUnprocessable();
    }
    public function test_owner_creates_cashier_unique_username_and_safe_logs(): void
    {
        [$owner,,$token] = $this->fixture('admin');
        $data = ['name'=>'Temporary cashier','username'=>'fixture_'.bin2hex(random_bytes(8)),'password'=>bin2hex(random_bytes(16))];
        $data['password_confirmation'] = $data['password'];
        $this->asToken($token)->postJson('/api/users',$data+['role'=>'admin'])->assertUnprocessable();
        $res = $this->asToken($token)->postJson('/api/users',$data)->assertCreated()->assertJsonPath('role','kasir')->assertJsonPath('is_active',true);
        $target = User::findOrFail($res->json('id'));
        $this->assertTrue(Hash::check($data['password'],$target->password));
        $this->assertArrayNotHasKey('password',$res->json());
        $this->asToken($token)->postJson('/api/users',$data)->assertUnprocessable();
        $log = ActivityLog::where('action','create-cashier')->latest('id')->first();
        $this->assertSame($owner->username,$log->user_name);
        $this->assertStringNotContainsString($data['password'],json_encode($log->toArray()));
    }
    public function test_deactivation_blocks_tokens_login_and_preserves_other_accounts(): void
    {
        [$owner,,$ownerToken] = $this->fixture('admin');
        [$target,$password,$targetToken] = $this->fixture();
        [$other,,$otherToken] = $this->fixture();
        $otherBefore = $this->digest($other); $hash = $target->password;
        $this->asToken($ownerToken)->patchJson('/api/users/'.$target->id.'/status',['is_active'=>false])->assertOk();
        $this->assertSame(0,$target->tokens()->count()); $this->assertSame($hash,$target->fresh()->password);
        $this->asToken($targetToken)->getJson('/api/me')->assertUnauthorized();
        $this->asToken(null)->postJson('/api/login',['username'=>$target->username,'password'=>$password])->assertUnauthorized();
        $this->asToken($otherToken)->getJson('/api/me')->assertOk(); $this->assertTrue($otherBefore === $this->digest($other));
        // Reject an already-issued token even if revocation was skipped externally.
        $stale = $target->createToken('inactive-fixture')->plainTextToken;
        $this->asToken($stale)->getJson('/api/me')->assertUnauthorized();
        $this->asToken($ownerToken)->patchJson('/api/users/'.$target->id.'/status',['is_active'=>true])->assertOk();
        $this->asToken(null)->postJson('/api/login',['username'=>$target->username,'password'=>$password])->assertOk();
        $this->asToken($ownerToken)->patchJson('/api/users/'.$owner->id.'/status',['is_active'=>false])->assertForbidden();
    }
    public function test_owner_reset_requires_reauthentication_and_isolates_tokens(): void
    {
        [$owner,$ownerPassword,$ownerToken] = $this->fixture('admin');
        [$target,$old,$targetToken] = $this->fixture();
        [$other,,$otherToken] = $this->fixture();
        $ownerBefore = $this->digest($owner); $otherBefore = $this->digest($other);
        $identity = $target->only(['id','name','username','role','is_active','remember_token','created_at']);
        $new = bin2hex(random_bytes(16)); $data=['current_password'=>$ownerPassword,'password'=>$new,'password_confirmation'=>$new];
        $this->asToken($ownerToken)->postJson('/api/users/'.$target->id.'/reset-password',array_replace($data,['current_password'=>'wrong']))->assertUnprocessable();
        $this->assertSame(1,$target->tokens()->count());
        $this->asToken($ownerToken)->postJson('/api/users/'.$target->id.'/reset-password',$data+['role'=>'admin'])->assertUnprocessable();
        $this->asToken($ownerToken)->postJson('/api/users/'.$owner->id.'/reset-password',$data)->assertForbidden();
        $res = $this->asToken($ownerToken)->postJson('/api/users/'.$target->id.'/reset-password',$data)->assertOk();
        $this->assertSame(['message'],array_keys($res->json()));
        $this->assertTrue(Hash::check($new,$target->fresh()->password));
        $this->assertEquals($identity,$target->fresh()->only(array_keys($identity)));
        $this->assertTrue($ownerBefore === $this->digest($owner)); $this->assertTrue($otherBefore === $this->digest($other));
        $this->assertSame(0,$target->tokens()->count()); $this->assertSame(1,$owner->tokens()->count()); $this->assertSame(1,$other->tokens()->count());
        $this->asToken($targetToken)->getJson('/api/me')->assertUnauthorized();
        $this->asToken(null)->postJson('/api/login',['username'=>$target->username,'password'=>$old])->assertUnauthorized();
        $this->asToken(null)->postJson('/api/login',['username'=>$target->username,'password'=>$new])->assertOk();
        $log=ActivityLog::where('action','reset-cashier-password')->latest('id')->first();
        $this->assertSame($owner->username,$log->user_name); $this->assertStringNotContainsString($new,json_encode($log->toArray()));
    }
    public function test_create_owner_command_hidden_input_and_duplicate_rejection(): void
    {
        $username='owner_fixture_'.bin2hex(random_bytes(8)); $password=bin2hex(random_bytes(16));
        $this->artisan('user:create-owner')->expectsQuestion('Name','Temporary owner')->expectsQuestion('Username',$username)->expectsQuestion('New password',$password)->expectsQuestion('Confirm password',$password)->expectsOutput('Akun Admin berhasil dibuat.')->assertExitCode(0);
        $owner=User::where('username',$username)->firstOrFail();
        $this->assertSame('admin',$owner->role); $this->assertTrue($owner->is_active); $this->assertTrue(Hash::check($password,$owner->password));
        $count=User::count();
        $this->artisan('user:create-owner')->expectsQuestion('Name','Temporary owner')->expectsQuestion('Username',$username)->expectsQuestion('New password',$password)->expectsQuestion('Confirm password',$password)->expectsOutput('Input tidak valid atau username sudah digunakan.')->assertExitCode(1);
        $this->assertSame($count,User::count());
        $source=file_get_contents(app_path('Console/Commands/CreateOwner.php'));
        $this->assertStringContainsString("secret('New password',false)",$source); $this->assertStringContainsString("secret('Confirm password',false)",$source);
    }
    public function test_role_migration_refuses_unsafe_rollback_before_ddl(): void
    {
        $migration=require database_path('migrations/2026_10_08_000002_add_role_and_active_status_to_users.php');
        $this->fixture('admin');
        $rejected=false; try { $migration->down(); } catch (\RuntimeException $e) { $rejected=true; }
        $this->assertTrue($rejected); $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users','role'));
    }
    public function test_cashier_activity_uses_authenticated_username(): void
    {
        [$user,,$token]=$this->fixture();
        $this->asToken($token)->postJson('/api/pengeluaran-toko',['tanggal'=>date('Y-m-d'),'kategori'=>'Lainnya','nama_pengeluaran'=>'Temporary expense','nominal'=>1000])->assertCreated()->assertJsonPath('kasir',$user->username);
        $this->assertSame($user->username,ActivityLog::latest('id')->first()->user_name);
    }
    public function test_all_protected_routes_require_authentication(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if (!in_array('auth:sanctum',$route->gatherMiddleware(),true)) continue;
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') continue;
                $this->withServerVariables(['REMOTE_ADDR'=>'198.18.'.random_int(0,255).'.'.random_int(1,254)]);
                $uri=preg_replace('/\{[^}]+\}/','999999',$route->uri());
                $this->asToken(null)->json($method,'/'.$uri,[])->assertUnauthorized();
            }
        }
    }
    public function test_role_schema_defaults_and_inactive_rollback_are_safe(): void
    {
        $role=DB::selectOne("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'");
        $this->assertSame("enum('kasir','admin')",$role->COLUMN_TYPE); $this->assertSame('NO',$role->IS_NULLABLE); $this->assertSame('kasir',$role->COLUMN_DEFAULT);
        [$user]=$this->fixture(); $user->is_active=false; $user->save();
        $migration=require database_path('migrations/2026_10_08_000002_add_role_and_active_status_to_users.php');
        $rejected=false; try { $migration->down(); } catch (\RuntimeException $e) { $rejected=true; }
        $this->assertTrue($rejected); $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users','is_active'));
    }
    public function test_owner_reset_validation_and_cli_confirmation_fail_without_changes(): void
    {
        [$owner,$plain,$token]=$this->fixture('admin'); [$target]=$this->fixture();
        $before=$this->digest($target);
        foreach ([['password'=>'short','password_confirmation'=>'short'],['password'=>str_repeat('A',73),'password_confirmation'=>str_repeat('A',73)],['password'=>str_repeat('A',12), 'password_confirmation'=>str_repeat('B',12)],['password'=>str_repeat('A',12)."\0", 'password_confirmation'=>str_repeat('A',12)."\0"]] as $passwords) $this->asToken($token)->postJson('/api/users/'.$target->id.'/reset-password',['current_password'=>$plain]+$passwords)->assertUnprocessable();
        $this->assertTrue($before === $this->digest($target));
        $count=User::count(); $password=bin2hex(random_bytes(16));
        $this->artisan('user:create-owner')->expectsQuestion('Name','Temporary owner')->expectsQuestion('Username','fixture_'.bin2hex(random_bytes(8)))->expectsQuestion('New password',$password)->expectsQuestion('Confirm password','mismatch')->expectsOutput('Input tidak valid atau username sudah digunakan.')->assertExitCode(1);
        $this->assertSame($count,User::count());
    }
    public function test_admin_rename_migration_is_reversible_and_rejects_unexpected_schema(): void
    {
        $migration=require database_path('migrations/2026_10_10_000001_rename_owner_role_to_admin.php');
        $before=hash('sha256',json_encode(DB::table('users')->orderBy('id')->get()));
        $migration->preflight(false);
        $rejected=false;
        try { $migration->preflight(true); } catch (\RuntimeException $e) { $rejected=true; }
        $this->assertTrue($rejected);
        // Capture SQL without DDL: real up/down/re-apply is tested separately outside transactions.
        $up=DB::pretend(fn()=>$migration->up()); $down=DB::pretend(fn()=>$migration->down());
        $this->assertCount(3,$up); $this->assertCount(3,$down);
        $this->assertSame("UPDATE users SET role='admin' WHERE role='pemilik'",$up[1]['query']);
        $this->assertSame("UPDATE users SET role='pemilik' WHERE role='admin'",$down[1]['query']);
        $this->assertStringContainsString("ENUM('kasir','admin')",$up[2]['query']);
        $this->assertStringContainsString("ENUM('kasir','pemilik')",$down[2]['query']);
        $this->assertTrue($before===hash('sha256',json_encode(DB::table('users')->orderBy('id')->get())));
    }
    public function test_frontend_role_contract_uses_admin_without_redesigning_operations(): void
    {
        $root=base_path('../frontend/src');
        foreach (['App.jsx','components/Layout/Sidebar.jsx','components/Layout/Header.jsx'] as $file) {
            $source=file_get_contents($root.'/'.$file);
            $this->assertStringContainsString("'admin'",$source);
            $this->assertStringNotContainsString("'pemilik'",$source);
        }
        $this->assertStringContainsString('Password Anda (Admin)',file_get_contents($root.'/pages/ManajemenPengguna.jsx'));
    }
}
