<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\BiayaAdminBrilink;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class FeeAuthorizationTest extends TestCase
{
    private function actor(string $role): User
    {
        $user=User::factory()->create(); $user->role=$role; $user->save();
        Sanctum::actingAs($user); return $user;
    }
    private function feesDigest(): string
    {
        return hash('sha256',json_encode(DB::table('biaya_admin_brilink')->orderBy('id')->get()));
    }
    private function payload(?string $nasabah=null): array
    {
        return ['jenis_nasabah'=>$nasabah,'ranges'=>[['nominal_min'=>1,'nominal_max'=>null,'biaya_admin'=>4321,'aktif'=>true]]];
    }
    public function test_both_roles_read_tariffs_without_any_writes_even_when_defaults_are_missing(): void
    {
        foreach (['kasir','admin'] as $role) {
            $this->actor($role); $before=$this->feesDigest();
            $this->getJson('/api/biaya-admin')->assertOk()->assertJsonStructure(['layanan','data']);
            $this->assertTrue($before===$this->feesDigest());
        }
        // Transactional fixture: removing one default group must not make GET recreate it.
        DB::table('biaya_admin_brilink')->where('jenis_transaksi','ewallet')->delete();
        $before=$this->feesDigest();
        foreach (['kasir','admin'] as $role) {
            $this->actor($role); $this->getJson('/api/biaya-admin')->assertOk();
            $this->assertFalse(DB::table('biaya_admin_brilink')->where('jenis_transaksi','ewallet')->exists());
            $this->assertTrue($before===$this->feesDigest());
        }
    }
    public function test_cashier_cannot_modify_any_existing_fee_endpoint_or_escalate_role(): void
    {
        $user=$this->actor('kasir'); $before=$this->feesDigest();
        foreach (array_keys(BiayaAdminBrilink::layanan()) as $service) {
            $payload=$this->payload(BiayaAdminBrilink::usesNasabah($service)?'internal':null);
            $this->putJson('/api/biaya-admin/'.$service,$payload)->assertForbidden();
            $this->putJson('/api/biaya-admin/'.$service,$payload+['role'=>'admin'])->assertForbidden();
        }
        $this->assertSame('kasir',$user->fresh()->role); $this->assertTrue($before===$this->feesDigest());
        // There are no standalone create/delete routes in the existing module.
        $this->postJson('/api/biaya-admin',$this->payload())->assertStatus(405);
        $this->deleteJson('/api/biaya-admin/ewallet')->assertStatus(405);
        $this->assertTrue($before===$this->feesDigest());
    }
    public function test_admin_can_set_tariff_and_cashier_uses_it_in_existing_brilink_workflow(): void
    {
        $admin=$this->actor('admin');
        // Isolate one tariff group in this test transaction; never mutate persistent baseline.
        DB::table('biaya_admin_brilink')->where('jenis_transaksi','ewallet')->delete();
        $this->putJson('/api/biaya-admin/ewallet',$this->payload())->assertOk()->assertJsonPath('0.biaya_admin',4321);
        $this->assertSame($admin->username,ActivityLog::latest('id')->first()->user_name);
        $this->actor('kasir');
        $this->getJson('/api/biaya-admin')->assertOk();
        $this->postJson('/api/ewallet',['jenis_transaksi'=>'top_up','provider'=>'BRILink Mobile','jenis_ewallet'=>'DANA','nomor_ewallet'=>'081200001234','nama_customer'=>'Temporary fee fixture','nominal'=>50000])->assertCreated()->assertJsonPath('biaya_admin',4321);
    }
    public function test_admin_can_set_internal_external_tariffs_and_other_groups_remain_unchanged(): void
    {
        $this->actor('admin');
        $other=hash('sha256',json_encode(DB::table('biaya_admin_brilink')->where('jenis_transaksi','<>','transfer')->orderBy('id')->get()));
        foreach (['internal','eksternal'] as $nasabah) {
            DB::table('biaya_admin_brilink')->where('jenis_transaksi','transfer')->where('jenis_nasabah',$nasabah)->delete();
            $this->putJson('/api/biaya-admin/transfer',$this->payload($nasabah))->assertOk()->assertJsonPath('0.jenis_nasabah',$nasabah);
            $this->assertSame(4321.0,BiayaAdminBrilink::hitung('transfer',50000,$nasabah));
        }
        $this->assertTrue($other===hash('sha256',json_encode(DB::table('biaya_admin_brilink')->where('jenis_transaksi','<>','transfer')->orderBy('id')->get())));
    }
    public function test_fee_endpoints_require_authentication_and_frontend_has_role_based_controls(): void
    {
        $this->getJson('/api/biaya-admin')->assertUnauthorized();
        $this->putJson('/api/biaya-admin/ewallet',$this->payload())->assertUnauthorized();
        $source=file_get_contents(base_path('../frontend/src/pages/PengaturanBiayaAdmin.jsx'));
        $this->assertStringContainsString("user?.role === 'admin'",$source);
        $this->assertSame(3,substr_count($source,'readOnly={!canManage}'));
        $this->assertStringContainsString('{canManage && <button',$source);
        foreach (['App.jsx','components/Layout/Sidebar.jsx','components/Layout/Header.jsx'] as $file) {
            $source=file_get_contents(base_path('../frontend/src/'.$file));
            preg_match('/const ownerPaths = (\[[^\n]+\])/',$source,$match);
            $this->assertStringContainsString("'/biaya-admin'",$match[1] ?? '');
        }
    }
}
