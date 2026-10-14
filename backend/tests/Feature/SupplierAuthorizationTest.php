<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class SupplierAuthorizationTest extends TestCase
{
    private function actor(string $role): User
    {
        $user = User::factory()->create(); $user->role = $role; $user->save();
        Sanctum::actingAs($user); return $user;
    }
    private function supplierData(): array
    {
        return ['nama_supplier'=>'Supplier fixture '.bin2hex(random_bytes(5)), 'no_telepon'=>'081200001234', 'alamat'=>'Alamat fixture', 'keterangan'=>'Catatan fixture'];
    }
    private function barangData(): array
    {
        return ['kode_barang'=>'SUP-'.bin2hex(random_bytes(8)), 'nama_barang'=>'Barang supplier fixture', 'kategori'=>'Aksesoris', 'stok'=>10, 'harga_beli'=>10000, 'harga_jual'=>15000];
    }
    public function test_admin_supplier_crud_and_safe_activity_metadata(): void
    {
        $admin = $this->actor('admin'); $data = $this->supplierData();
        $id = $this->postJson('/api/suppliers', $data)->assertCreated()->assertJsonPath('barang_count', 0)->json('id');
        $this->getJson('/api/suppliers')->assertOk()->assertJsonFragment(['id'=>$id, 'nama_supplier'=>$data['nama_supplier']]);
        $this->getJson('/api/suppliers/'.$id)->assertOk()->assertJsonPath('alamat', $data['alamat']);
        foreach (['putJson','patchJson'] as $method) {
            $data['nama_supplier'] .= ' edit';
            $this->$method('/api/suppliers/'.$id, $data)->assertOk()->assertJsonPath('nama_supplier', $data['nama_supplier']);
        }
        $this->deleteJson('/api/suppliers/'.$id)->assertOk();
        $this->assertDatabaseMissing('suppliers', ['id'=>$id]);
        $logs = ActivityLog::where('module','Supplier')->get(); $this->assertCount(4, $logs);
        foreach ($logs as $log) {
            $this->assertSame($admin->username, $log->user_name);
            $this->assertSame(['supplier_id','nama_supplier'], array_keys($log->properties));
            foreach (['no_telepon','alamat','keterangan','password','token'] as $key) $this->assertArrayNotHasKey($key, $log->properties);
        }
    }
    public function test_cashier_reads_but_all_supplier_writes_are_forbidden(): void
    {
        $supplier = Supplier::create($this->supplierData()); $user = $this->actor('kasir');
        $before = hash('sha256', json_encode($supplier->fresh()->getRawOriginal()));
        $this->getJson('/api/suppliers')->assertOk(); $this->getJson('/api/suppliers/'.$supplier->id)->assertOk();
        foreach (['POST','PUT','PATCH','DELETE'] as $method) {
            $path = '/api/suppliers'.($method === 'POST' ? '' : '/'.$supplier->id);
            $this->json($method, $path, $this->supplierData()+['role'=>'admin'])->assertForbidden();
        }
        $this->assertSame('kasir', $user->fresh()->role);
        $this->assertTrue($before === hash('sha256', json_encode($supplier->fresh()->getRawOriginal())));
        $this->assertSame(1, Supplier::count());
    }
    public function test_anonymous_supplier_routes_require_authentication(): void
    {
        foreach ([['GET',''],['GET','/999999'],['POST',''],['PUT','/999999'],['PATCH','/999999'],['DELETE','/999999']] as [$method,$suffix]) {
            $this->json($method, '/api/suppliers'.$suffix, $this->supplierData())->assertUnauthorized();
        }
    }
    public function test_cashier_selects_supplier_for_multiple_items_and_legacy_updates_keep_it(): void
    {
        $supplier = Supplier::create($this->supplierData()); $this->actor('kasir');
        $data = $this->barangData();
        $id = $this->postJson('/api/barang', $data+['supplier_id'=>$supplier->id])->assertCreated()->assertJsonPath('supplier.nama_supplier', $supplier->nama_supplier)->json('id');
        $other = Barang::create($this->barangData()+['supplier_id'=>$supplier->id]);
        $this->assertSame(2, $supplier->barang()->count());
        $this->getJson('/api/barang/'.$id)->assertOk()->assertJsonPath('supplier.id', $supplier->id);
        $this->getJson('/api/barang')->assertOk()->assertJsonFragment(['nama_supplier'=>$supplier->nama_supplier]);
        $this->getJson('/api/barang-search?q='.$data['kode_barang'])->assertOk()->assertJsonPath('0.supplier.id', $supplier->id);
        $this->putJson('/api/barang/'.$id, $data)->assertOk()->assertJsonPath('supplier_id', $supplier->id);
        $this->postJson('/api/barang/import', ['items'=>[$data]])->assertOk();
        $this->assertSame($supplier->id, Barang::findOrFail($id)->supplier_id);
        $this->putJson('/api/barang/'.$id, $data+['supplier_id'=>null])->assertOk()->assertJsonPath('supplier', null);
        $this->assertSame(10, Barang::findOrFail($id)->stok);
        $this->assertSame('10000.00', Barang::findOrFail($id)->harga_beli);
        $this->assertSame('15000.00', Barang::findOrFail($id)->harga_jual);
        $this->assertSame($supplier->id, $other->fresh()->supplier_id);
        $this->actor('admin'); $this->getJson('/api/barang/'.$other->id)->assertOk()->assertJsonPath('supplier.id', $supplier->id);
        $this->postJson('/api/barang', $this->barangData())->assertForbidden();
    }
    public function test_used_supplier_delete_is_blocked_by_api_and_foreign_key_without_deleting_goods(): void
    {
        $supplier = Supplier::create($this->supplierData());
        $barang = Barang::create($this->barangData()+['supplier_id'=>$supplier->id]);
        $before = hash('sha256', json_encode($barang->fresh()->getRawOriginal())); $this->actor('admin');
        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertUnprocessable();
        $rejected = false;
        try { $supplier->delete(); } catch (\Illuminate\Database\QueryException $e) { $rejected = ($e->errorInfo[1] ?? null) === 1451; }
        $this->assertTrue($rejected);
        $this->assertDatabaseHas('suppliers', ['id'=>$supplier->id]);
        $this->assertTrue($before === hash('sha256', json_encode($barang->fresh()->getRawOriginal())));
        $migration = require database_path('migrations/2026_10_10_000002_create_suppliers_and_link_barang.php');
        $rejected = false; try { $migration->preflight(false); } catch (\RuntimeException $e) { $rejected = true; }
        $this->assertTrue($rejected); $this->assertTrue(Schema::hasTable('suppliers'));
    }
    public function test_validation_nullable_supplier_and_missing_supplier_are_safe(): void
    {
        $this->actor('admin');
        $this->postJson('/api/suppliers', [])->assertUnprocessable();
        foreach (['nama_supplier'=>151, 'no_telepon'=>31, 'alamat'=>256, 'keterangan'=>501] as $key=>$length) {
            $this->postJson('/api/suppliers', array_replace($this->supplierData(), [$key=>str_repeat('A', $length)]))->assertUnprocessable();
        }
        $this->postJson('/api/suppliers', ['nama_supplier'=>'Nullable supplier fixture'])->assertCreated()->assertJsonPath('no_telepon', null)->assertJsonPath('alamat', null)->assertJsonPath('keterangan', null);
        $this->actor('kasir');
        $this->postJson('/api/barang', $this->barangData()+['supplier_id'=>999999])->assertUnprocessable();
        $this->postJson('/api/barang/import', ['items'=>[$this->barangData()+['supplier_id'=>999999]]])->assertUnprocessable();
        $id = $this->postJson('/api/barang', $this->barangData())->assertCreated()->json('id');
        $this->assertNull(Barang::findOrFail($id)->supplier_id);
    }
    public function test_supplier_schema_and_existing_unassigned_goods_are_valid(): void
    {
        $column = DB::selectOne("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barang' AND COLUMN_NAME='supplier_id'");
        $this->assertSame('int unsigned', $column->COLUMN_TYPE); $this->assertSame('YES', $column->IS_NULLABLE);
        $this->assertFalse(DB::table('barang')->whereNotNull('supplier_id')->exists());
        $id = DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suppliers' AND COLUMN_NAME='id'");
        $this->assertSame('int unsigned', $id->COLUMN_TYPE);
        $this->assertSame(['id','nama_supplier','no_telepon','alamat','keterangan','created_at','updated_at'], Schema::getColumnListing('suppliers'));
    }
    public function test_read_routes_do_not_write_and_frontend_contract_uses_existing_roles(): void
    {
        $supplier = Supplier::create($this->supplierData());
        foreach (['kasir','admin'] as $role) {
            $this->actor($role);
            $before = hash('sha256', json_encode(DB::table('suppliers')->orderBy('id')->get()));
            $this->getJson('/api/suppliers')->assertOk(); $this->getJson('/api/suppliers/'.$supplier->id)->assertOk();
            $this->assertTrue($before === hash('sha256', json_encode(DB::table('suppliers')->orderBy('id')->get())));
        }
        $root = base_path('../frontend/src'); $source = file_get_contents($root.'/pages/DataSupplier.jsx');
        $this->assertStringContainsString("user?.role === 'admin'", $source);
        $this->assertStringContainsString('{canManage && <button', $source);
        $this->assertStringContainsString('item.barang_count > 0', $source);
        $barang = file_get_contents($root.'/pages/DataBarang.jsx');
        $this->assertStringContainsString('item.supplier?.nama_supplier', $barang);
        $this->assertStringContainsString('Supplier Utama (opsional)', $barang);
        foreach (['App.jsx','components/Layout/Sidebar.jsx','components/Layout/Header.jsx'] as $file) $this->assertStringContainsString("'/data-supplier'", file_get_contents($root.'/'.$file));
    }
}
