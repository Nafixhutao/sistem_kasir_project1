<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TARGETS = [
        'pembayaran_qris_sale_unique' => 'transaksi_penjualan_id',
        'pembayaran_qris_transaction_id_unique' => 'transaction_id',
    ];
    // Durable ownership: rollback must not remove a pre-existing index.
    private const OWNER = 'sultan_cell:2026_10_11_000004_complete_qris_unique_indexes';
    private array $initial;

    public function __construct()
    {
        // Captured before Laravel's pretend mode (metadata reads otherwise return []).
        // On a fresh install the table is created by an earlier migration that has not
        // run yet, because Laravel instantiates every migration up front.
        $this->initial = Schema::hasTable('pembayaran_qris') ? $this->indexes() : [];
    }

    private function indexes(): array
    {
        $result = [];
        foreach (DB::select('SHOW INDEX FROM `pembayaran_qris`') as $part) {
            $result[$part->Key_name][] = $part;
        }
        return $result;
    }

    private function validateDefinition(array $parts, string $column): void
    {
        $p = $parts[0];
        if (count($parts) !== 1 || $p->Column_name !== $column || (int)$p->Non_unique !== 0
            || $p->Sub_part !== null || $p->Index_type !== 'BTREE'
            || ($p->Expression ?? null) !== null || ($p->Visible ?? 'YES') !== 'YES') {
            throw new RuntimeException('Unexpected QRIS unique index definition; no DDL performed.');
        }
    }

    public function preflight(): array
    {
        $columns = [];
        foreach (DB::select('SHOW COLUMNS FROM `pembayaran_qris`') as $c) $columns[$c->Field] = $c;
        if (($columns['transaksi_penjualan_id']->Type ?? '') !== 'int unsigned'
            || ($columns['transaksi_penjualan_id']->Null ?? '') !== 'NO'
            || ($columns['transaction_id']->Type ?? '') !== 'varchar(100)'
            || ($columns['transaction_id']->Null ?? '') !== 'YES') {
            throw new RuntimeException('Unexpected QRIS columns; no DDL performed.');
        }
        $indexes = $this->indexes();
        foreach (self::TARGETS as $name => $column) {
            if (isset($indexes[$name])) $this->validateDefinition($indexes[$name], $column);
            foreach ($indexes as $other => $parts) {
                if ($other !== $name && count($parts) === 1 && $parts[0]->Column_name === $column
                    && (int)$parts[0]->Non_unique === 0) {
                    throw new RuntimeException('Equivalent QRIS unique key has an unexpected name; review required.');
                }
            }
            if (DB::table('pembayaran_qris')->whereNotNull($column)->select($column)
                ->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Duplicate QRIS keys require review; no data changed.');
            }
        }
        return $indexes;
    }

    public function up(): void
    {
        $indexes = DB::connection()->pretending() ? $this->initial : $this->preflight();
        $clauses = [];
        foreach (self::TARGETS as $name => $column) if (!isset($indexes[$name])) {
            $clauses[] = "ADD UNIQUE INDEX `{$name}` (`{$column}`) COMMENT '".self::OWNER."'";
        }
        // One ALTER after both data/index checks; never rewrite payment rows.
        if ($clauses) DB::statement('ALTER TABLE `pembayaran_qris` '.implode(', ', $clauses));
    }

    public function down(): void
    {
        $indexes = DB::connection()->pretending() ? $this->initial : $this->preflight();
        $drops = [];
        foreach (self::TARGETS as $name => $column) {
            if (isset($indexes[$name]) && $indexes[$name][0]->Index_comment === self::OWNER) {
                $this->validateDefinition($indexes[$name], $column);
                $drops[$name] = 'DROP INDEX `'.$name.'`';
            }
        }
        if (isset($drops['pembayaran_qris_sale_unique'])) {
            $support = false;
            foreach ($indexes as $name => $parts) if (!isset($drops[$name])
                && $parts[0]->Column_name === 'transaksi_penjualan_id') $support = true;
            if (!$support) throw new RuntimeException('Rollback would remove QRIS foreign-key supporting index; review required.');
        }
        if ($drops) DB::statement('ALTER TABLE `pembayaran_qris` '.implode(', ', $drops));
    }
};
