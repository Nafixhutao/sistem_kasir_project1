<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES=['transaksi_penjualan','retur_pelanggan','retur_supplier'];
    private array $plan=[];

    public function __construct()
    {
        // Laravel resolves migrations before entering pretend mode. Read metadata now
        // so pretend emits the actual delta, rather than falsely adding existing keys.
        foreach (self::TABLES as $t) $this->plan[$t]=[
            'column'=>Schema::hasColumn($t,'request_id'),
            'index'=>(bool)DB::selectOne('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$t,$t.'_request_id_unique']),
        ];
    }

    public function preflight(): void
    {
        foreach (self::TABLES as $t) {
            if (!Schema::hasTable($t) || Schema::hasColumn($t,'request_hash')) throw new RuntimeException('Unexpected idempotency transition schema.');
            if (Schema::hasColumn($t,'request_id')) {
                $c=DB::selectOne('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$t,'request_id']);
                if ($c->COLUMN_TYPE!=='char(36)' || $c->IS_NULLABLE!=='YES') throw new RuntimeException('Unexpected request ID column.');
                if (DB::table($t)->whereNotNull('request_id')->exists()) throw new RuntimeException('Unfingerprinted requests require manual review.');
            }
            $indexes=DB::select('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);
            foreach ($indexes as $i) if ($i->INDEX_NAME===$t.'_request_id_unique') {
                $parts=array_filter($indexes,fn($p)=>$p->INDEX_NAME===$i->INDEX_NAME);
                if (count($parts)!==1 || $i->COLUMN_NAME!=='request_id' || (int)$i->NON_UNIQUE!==0) throw new RuntimeException('Unexpected request ID index.');
            }
        }
    }

    public function up(): void
    {
        if (!DB::connection()->pretending()) $this->preflight();
        foreach (self::TABLES as $t) {
            // Reconstruct the missing legacy schema declaratively, without duplicating it.
            if (!$this->plan[$t]['column']) Schema::table($t,fn(Blueprint $b)=>$b->char('request_id',36)->nullable());
            if (!$this->plan[$t]['index']) Schema::table($t,fn(Blueprint $b)=>$b->unique('request_id',$t.'_request_id_unique'));
            Schema::table($t,fn(Blueprint $b)=>$b->char('request_hash',64)->nullable());
        }
    }

    public function down(): void
    {
        if (!DB::connection()->pretending()) foreach (self::TABLES as $t) {
            if (!Schema::hasColumn($t,'request_hash') || DB::table($t)->whereNotNull('request_hash')->exists()) throw new RuntimeException('Rollback refused: processed request fingerprints must be preserved.');
        }
        foreach (self::TABLES as $t) Schema::table($t,fn(Blueprint $b)=>$b->dropColumn('request_hash'));
        // Preserve the pre-existing request_id columns/unique constraints and their data.
        // On a fresh installation these safety keys are intentionally retained too.
    }
};
