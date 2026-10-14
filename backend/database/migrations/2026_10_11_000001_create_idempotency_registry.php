<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const SOURCES=['penjualan'=>'transaksi_penjualan','retur_pelanggan'=>'retur_pelanggan','retur_supplier'=>'retur_supplier'];

    public function preflight(): void
    {
        if (Schema::hasTable('idempotency_records')) throw new RuntimeException('Registry already exists; inspect actual state.');
        foreach (self::SOURCES as $table) {
            foreach (['request_id'=>'char(36)','request_hash'=>'char(64)'] as $field=>$type) {
                $c=DB::selectOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$field]);
                if (!$c || $c->COLUMN_TYPE!==$type) throw new RuntimeException('Request metadata migration required.');
            }
            $index=DB::select('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$table.'_request_id_unique']);
            if (count($index)!==1 || $index[0]->COLUMN_NAME!=='request_id' || (int)$index[0]->NON_UNIQUE!==0) throw new RuntimeException('Request unique index required.');
            DB::table($table)->whereNotNull('request_id')->orderBy('id')->chunkById(200,function ($rows) {
                foreach ($rows as $r) if (!\Illuminate\Support\Str::isUuid($r->request_id) || !is_string($r->request_hash) || !preg_match('/^[a-f0-9]{64}$/D',$r->request_hash)) throw new RuntimeException('Unverifiable processed request; stop before DDL.');
            });
        }
    }

    public function up(): void
    {
        if (!DB::connection()->pretending()) $this->preflight();
        Schema::create('idempotency_records',function (Blueprint $t) {
            $t->increments('id');
            $t->string('scope',32)->charset('ascii')->collation('ascii_bin');
            $t->char('request_id',36)->charset('ascii')->collation('ascii_bin');
            $t->char('request_hash',64)->charset('ascii')->collation('ascii_bin');
            $t->enum('status',['completed'])->default('completed');
            $t->unsignedInteger('result_id');
            $t->timestamps();
            $t->unique(['scope','request_id'],'idempotency_scope_request_unique');
            // No cascading FK: a successful key must survive deletion of its business row.
        });
        foreach (self::SOURCES as $scope=>$table) DB::table($table)->whereNotNull('request_id')->orderBy('id')->chunkById(200,function ($rows) use ($scope) {
            foreach ($rows as $r) DB::table('idempotency_records')->insert([
                'scope'=>$scope,'request_id'=>strtolower($r->request_id),'request_hash'=>$r->request_hash,
                'status'=>'completed','result_id'=>$r->id,'created_at'=>$r->created_at,'updated_at'=>$r->created_at,
            ]);
        });
    }

    public function down(): void
    {
        if (!DB::connection()->pretending() && (!Schema::hasTable('idempotency_records') || DB::table('idempotency_records')->exists())) throw new RuntimeException('Rollback refused: successful registry keys must not be erased.');
        Schema::drop('idempotency_records'); // Only a verified empty, newly introduced table.
    }
};
