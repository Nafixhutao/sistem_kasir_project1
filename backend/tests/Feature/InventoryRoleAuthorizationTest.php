<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryRoleAuthorizationTest extends TestCase
{
    private function actor(string $role): User
    {
        $user=User::factory()->create();
        $user->role=$role; $user->save();
        Sanctum::actingAs($user);
        return $user;
    }

    private function item(): array
    {
        return ['kode_barang'=>'FINAL-'.bin2hex(random_bytes(8)),
            'nama_barang'=>'Barang final authorization fixture','kategori'=>'Aksesoris',
            'stok'=>10,'harga_beli'=>10000,'harga_jual'=>15000];
    }

    public function test_admin_reads_inventory_but_all_inventory_writes_are_forbidden(): void
    {
        $data=$this->item();$barang=Barang::create($data);$admin=$this->actor('admin');
        // Compare stored rows on both sides (DECIMAL/defaults are normalized by MySQL).
        $before=$barang->fresh()->getRawOriginal();$logCount=ActivityLog::count();
        $this->getJson('/api/barang')->assertOk()->assertJsonFragment(['id'=>$barang->id]);
        $this->getJson('/api/barang/'.$barang->id)->assertOk()->assertJsonPath('kode_barang',$data['kode_barang']);
        $this->getJson('/api/barang-search?q='.$data['kode_barang'])->assertOk();
        foreach(['POST','PUT','PATCH','DELETE'] as $method){
            $url='/api/barang'.($method==='POST'?'':'/'.$barang->id);
            $this->json($method,$url,array_replace($data,['stok'=>99,'role'=>'kasir']))->assertForbidden();
        }
        $this->postJson('/api/barang/import',['items'=>[$data]])->assertForbidden();
        $this->assertSame($before,$barang->fresh()->getRawOriginal());
        $this->assertSame($logCount,ActivityLog::count());
        $this->assertSame('admin',$admin->fresh()->role);
    }

    public function test_cashier_creates_puts_and_patches_stock_with_optional_supplier(): void
    {
        $supplier=Supplier::create(['nama_supplier'=>'Supplier final authorization fixture']);
        $cashier=$this->actor('kasir');$data=$this->item()+['supplier_id'=>$supplier->id];
        $this->getJson('/api/barang')->assertOk();
        $id=$this->postJson('/api/barang',$data)->assertCreated()
            ->assertJsonPath('supplier_id',$supplier->id)->assertJsonPath('stok',10)->json('id');
        $this->getJson('/api/barang/'.$id)->assertOk()->assertJsonPath('supplier.id',$supplier->id);
        $data['stok']=12;
        $this->putJson('/api/barang/'.$id,$data)->assertOk()->assertJsonPath('stok',12);
        $data['stok']=15;$data['supplier_id']=null;
        $this->patchJson('/api/barang/'.$id,$data)->assertOk()
            ->assertJsonPath('stok',15)->assertJsonPath('supplier_id',null);
        $row=Barang::findOrFail($id);
        $this->assertSame(15,(int)$row->stok);
        $this->assertNull($row->supplier_id);
        $this->assertSame('10000.00',$row->harga_beli);
        $this->assertSame('15000.00',$row->harga_jual);
        $this->assertSame('kasir',$cashier->fresh()->role);
        foreach(ActivityLog::where('module','Barang')->where('description','like','%'.$data['nama_barang'].'%')->get() as $log){
            $this->assertSame($cashier->username,$log->user_name);
        }
        $this->deleteJson('/api/barang/'.$id)->assertOk();
        $this->assertDatabaseMissing('barang',['id'=>$id]);
    }
}
