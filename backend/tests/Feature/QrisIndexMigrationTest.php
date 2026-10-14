<?php
namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrisIndexMigrationTest extends TestCase
{
    private function payment(?string $transactionId=null): array
    {
        $sale=DB::table('transaksi_penjualan')->insertGetId([
            'kode_transaksi'=>'QK-'.Str::random(20),'tanggal'=>today()->toDateString(),
            'metode_pembayaran'=>'qris','status'=>'lunas','total_harga'=>12000,'kasir'=>'qris_index_fixture',
        ]);
        return ['order_id'=>'QK-'.Str::random(20),'transaksi_penjualan_id'=>$sale,'nominal'=>12000,'transaction_id'=>$transactionId];
    }

    public function test_verified_existing_indexes_are_not_duplicated_or_owned_by_the_new_migration(): void
    {
        $before=DB::select('SHOW INDEX FROM pembayaran_qris');
        $rows=hash('sha256',json_encode(DB::table('pembayaran_qris')->orderBy('id')->get()));
        $migration=require database_path('migrations/2026_10_11_000004_complete_qris_unique_indexes.php');
        $migration->preflight();$migration->up();$migration->down();
        $this->assertEquals($before,DB::select('SHOW INDEX FROM pembayaran_qris'));
        $this->assertSame($rows,hash('sha256',json_encode(DB::table('pembayaran_qris')->orderBy('id')->get())));
    }

    public function test_one_payment_per_sale_is_enforced_by_the_database(): void
    {
        $row=$this->payment();DB::table('pembayaran_qris')->insert($row);
        $row['order_id']='QK-'.Str::random(20);
        try{DB::table('pembayaran_qris')->insert($row);$this->fail('Duplicate sale payment accepted.');}
        catch(QueryException $e){$this->assertSame(1062,$e->errorInfo[1]);}
        $this->assertSame(1,DB::table('pembayaran_qris')->where('transaksi_penjualan_id',$row['transaksi_penjualan_id'])->count());
    }

    public function test_one_midtrans_transaction_cannot_be_bound_to_two_sales(): void
    {
        $id='QK-'.Str::random(20);DB::table('pembayaran_qris')->insert($this->payment($id));
        try{DB::table('pembayaran_qris')->insert($this->payment($id));$this->fail('Duplicate Midtrans ID accepted.');}
        catch(QueryException $e){$this->assertSame(1062,$e->errorInfo[1]);}
        $this->assertSame(1,DB::table('pembayaran_qris')->where('transaction_id',$id)->count());
    }

    public function test_multiple_null_midtrans_ids_do_not_block_distinct_payments(): void
    {
        $a=$this->payment();$b=$this->payment();DB::table('pembayaran_qris')->insert([$a,$b]);
        $this->assertSame(2,DB::table('pembayaran_qris')->whereIn('transaksi_penjualan_id',[$a['transaksi_penjualan_id'],$b['transaksi_penjualan_id']])->whereNull('transaction_id')->count());
    }
}
