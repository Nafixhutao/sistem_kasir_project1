<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validation and FK ordering for the existing JSON business backup format. */
final class BusinessBackup
{
    public const TABLES = [
        'suppliers', 'barang', 'transaksi_penjualan', 'detail_penjualan', 'pembayaran_qris',
        'transaksi_transfer', 'transaksi_tarik_tunai', 'transaksi_setor_tunai',
        'pembayaran_tagihan', 'transaksi_pulsa', 'transaksi_ewallet', 'biaya_admin',
        'biaya_admin_brilink', 'providers', 'activity_logs', 'pengeluaran_toko',
        'retur_pelanggan', 'retur_supplier', 'idempotency_records',
    ];
    /** Tabel versi lama yang sudah dihapus dari skema; masih boleh ada di file backup lama dan diabaikan saat restore. */
    private const LEGACY_TABLES = ['retur_penjualan'];
    private const OPTIONAL = ['suppliers', 'retur_supplier', 'retur_pelanggan', 'providers', 'idempotency_records'];

    public static function metadata(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled !== 1
            || !str_contains(DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode, 'STRICT_')) {
            self::invalid('Restore membutuhkan MySQL strict mode dengan foreign key aktif.');
        }
        $columns = []; $engines = [];
        foreach (DB::select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
            if (in_array($table->TABLE_NAME, self::TABLES, true)) {
                if ($table->ENGINE !== 'InnoDB') self::invalid('Tabel backup harus mendukung transaksi InnoDB.');
                $engines[$table->TABLE_NAME] = true;
            }
        }
        foreach (DB::select('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY ORDINAL_POSITION') as $column) {
            if (isset($engines[$column->TABLE_NAME])) $columns[$column->TABLE_NAME][$column->COLUMN_NAME] = $column;
        }
        $foreign = DB::select('SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        $indexes = DB::select('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
        return [$columns, $foreign, $indexes];
    }

    /** Called inside the transaction, before any DELETE/INSERT. */
    public static function prepare(array $backup): array
    {
        if (($backup['app'] ?? null) !== 'Sultan Cell' || !is_string($backup['backup_at'] ?? null)
            || strtotime($backup['backup_at']) === false || !is_array($backup['tables'] ?? null)) self::invalid('Format backup tidak valid.');
        [$columns, $foreign, $indexes] = self::metadata();
        $tables = array_diff_key($backup['tables'], array_fill_keys(self::LEGACY_TABLES, null));
        if (array_diff(array_keys($tables), array_keys($columns))) self::invalid('Backup memuat tabel yang tidak diizinkan.');
        $missing = array_diff(array_keys($columns), array_keys($tables), self::OPTIONAL);
        if ($missing) self::invalid('File backup tidak lengkap: '.implode(', ', $missing).'.');
        $prepared = [];
        foreach ($tables as $table => $rows) {
            if (!is_array($rows) || !array_is_list($rows)) self::invalid('Isi tabel backup harus berupa daftar row.');
            $prepared[$table] = [];
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row), array_keys($columns[$table]))) self::invalid('Kolom backup tidak sesuai schema.');
                $normalized = [];
                foreach ($columns[$table] as $name => $column) {
                    if (!array_key_exists($name, $row)) {
                        if ($column->COLUMN_KEY === 'PRI' || $column->IS_NULLABLE !== 'YES') self::invalid('Data wajib hilang pada '.$table.'.'.$name.'.');
                        if ($column->COLUMN_DEFAULT !== null && preg_match('/current_timestamp/i', $column->COLUMN_DEFAULT)) self::invalid('Timestamp wajib disertakan dalam backup.');
                        $row[$name] = $column->COLUMN_DEFAULT;
                    }
                    $normalized[$name] = self::value($row[$name], $column);
                }
                $prepared[$table][] = $normalized;
            }
        }
        if (isset($columns['idempotency_records'])) self::retainRegistry($prepared);
        $groups = []; $collations = []; $padding = []; $charsets = [];
        foreach ($indexes as $index) {
            if (!isset($prepared[$index->TABLE_NAME])) continue;
            if ($index->SUB_PART !== null || $index->COLUMN_NAME === null) self::invalid('Index backup memerlukan review khusus.');
            $groups[$index->TABLE_NAME][$index->INDEX_NAME][] = $index->COLUMN_NAME;
        }
        foreach (DB::select('SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLLATION_NAME IS NOT NULL') as $c) $collations[$c->TABLE_NAME][$c->COLUMN_NAME] = $c->COLLATION_NAME;
        foreach (DB::select('SELECT COLLATION_NAME, PAD_ATTRIBUTE FROM information_schema.COLLATIONS') as $c) $padding[$c->COLLATION_NAME] = $c->PAD_ATTRIBUTE;
        foreach (DB::select('SELECT COLLATION_NAME, CHARACTER_SET_NAME FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY') as $c) $charsets[$c->COLLATION_NAME] = $c->CHARACTER_SET_NAME;
        foreach ($groups as $table => $unique) foreach ($unique as $fields) {
            $seen = [];
            foreach ($prepared[$table] as $row) {
                $key = []; $skip = false;
                foreach ($fields as $field) {
                    if ($row[$field] === null) { $skip = true; break; }
                    if (isset($collations[$table][$field])) {
                        $collation = $collations[$table][$field];
                        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $collation)) self::invalid('Collation tidak didukung.');
                        $text = (string)$row[$field];
                        if (($padding[$collation] ?? '') === 'PAD SPACE') $text = rtrim($text, ' ');
                        $charset=$charsets[$collation] ?? '';
                        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$charset)) self::invalid('Charset tidak didukung.');
                        $key[] = DB::selectOne('SELECT HEX(WEIGHT_STRING(CONVERT(? USING '.$charset.') COLLATE '.$collation.')) AS weight', [$text])->weight;
                    } else $key[] = (string)$row[$field];
                }
                if ($skip) continue;
                $encoded = json_encode($key, JSON_THROW_ON_ERROR);
                if (isset($seen[$encoded])) self::invalid('Nilai primary/unique duplikat pada backup '.$table.'.');
                $seen[$encoded] = true;
            }
        }
        $dependencies = array_fill_keys(array_keys($prepared), []);
        foreach ($foreign as $fk) {
            $child = $fk->TABLE_NAME; $parent = $fk->REFERENCED_TABLE_NAME;
            if (isset($prepared[$parent]) && !isset($prepared[$child]) && DB::table($child)->whereNotNull($fk->COLUMN_NAME)->exists()) self::invalid('Backup tidak mencakup tabel terkait '.$child.'.');
            if (!isset($prepared[$child])) continue;
            if (isset($prepared[$parent])) $dependencies[$child][] = $parent;
            $ids = isset($prepared[$parent]) ? array_column($prepared[$parent], $fk->REFERENCED_COLUMN_NAME) : DB::table($parent)->pluck($fk->REFERENCED_COLUMN_NAME)->all();
            $lookup = array_fill_keys(array_map('strval', $ids), true);
            foreach ($prepared[$child] as $row) {
                $value = $row[$fk->COLUMN_NAME];
                if ($value !== null && !isset($lookup[(string)$value])) self::invalid('Relasi backup tidak valid: '.$child.'.'.$fk->COLUMN_NAME.'.');
            }
        }
        $order = [];
        while ($dependencies) {
            $progress = false;
            foreach ($dependencies as $table => $parents) {
                if (array_diff($parents, $order)) continue;
                $order[] = $table; unset($dependencies[$table]); $progress = true;
            }
            if (!$progress) self::invalid('Dependency backup memerlukan review khusus.');
        }
        return [$prepared, $order];
    }

    /** Completed keys are durable security metadata, not a replaceable business snapshot. */
    private static function retainRegistry(array &$prepared): void
    {
        // Current locking read, including the end gap, prevents a concurrently
        // committed receipt from being lost while restore replaces the snapshot.
        $current=DB::table('idempotency_records')->orderBy('id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
        $map=[]; $used=[]; $next=0;
        $valid=function(array $r): string {
            if (!isset(\App\Models\IdempotencyRecord::SCOPES[$r['scope']])
                || !is_string($r['request_id']) || !is_string($r['request_hash'])
                || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$r['request_id'])
                || !preg_match('/^[0-9a-f]{64}$/D',$r['request_hash'])
                || $r['status']!=='completed' || $r['result_id']<=0) self::invalid('Metadata registry idempotency tidak valid.');
            return $r['scope'].':'.$r['request_id'];
        };
        foreach ($current as $r) { $key=$valid($r); $map[$key]=$r; $used[$r['id']]=true; $next=max($next,$r['id']); }
        $incomingKeys=[]; $incomingIds=[];
        foreach ($prepared['idempotency_records'] ?? [] as $r) {
            $key=$valid($r);
            if (isset($incomingKeys[$key]) || isset($incomingIds[$r['id']])) self::invalid('Registry backup duplikat.');
            $incomingKeys[$key]=true; $incomingIds[$r['id']]=true; $next=max($next,$r['id']);
        }
        $merge=function(array $r) use (&$map,&$used,&$next,$valid): void {
            $key=$valid($r);
            if (isset($map[$key])) {
                if (!hash_equals($map[$key]['request_hash'],$r['request_hash']) || (int)$map[$key]['result_id']!==(int)$r['result_id']) self::invalid('Registry backup bertentangan dengan request yang sudah diproses.');
                return;
            }
            if (!isset($r['id']) || isset($used[$r['id']])) $r['id']=++$next;
            if ($r['id']>4294967295) self::invalid('ID registry di luar batas.');
            $used[$r['id']]=true; $map[$key]=$r;
        };
        foreach ($prepared['idempotency_records'] ?? [] as $r) $merge($r);
        foreach (\App\Models\IdempotencyRecord::SCOPES as $scope=>$model) {
            $table=(new $model)->getTable();
            foreach ($prepared[$table] ?? [] as $r) {
                if (($r['request_id'] ?? null)===null) continue;
                // Legacy backups can reconstruct only verifiable completed metadata.
                $merge(['scope'=>$scope,'request_id'=>$r['request_id'],'request_hash'=>$r['request_hash'] ?? '',
                    'status'=>'completed','result_id'=>$r['id'],'created_at'=>$r['created_at'] ?? null,'updated_at'=>$r['created_at'] ?? null]);
            }
        }
        $prepared['idempotency_records']=array_values($map);
    }

    private static function value($value, object $column)
    {
        if ($value === null) {
            if ($column->IS_NULLABLE !== 'YES') self::invalid('Nilai wajib tidak boleh NULL.');
            return null;
        }
        $type = $column->DATA_TYPE;
        if ($type === 'json') {
            try {
                if (!is_string($value)) return json_encode($value, JSON_THROW_ON_ERROR);
                json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) { self::invalid('JSON pada backup tidak valid.'); }
            return $value;
        }
        if (!is_scalar($value)) self::invalid('Nilai kolom backup tidak valid.');
        if (in_array($type, ['tinyint','smallint','mediumint','int','bigint'], true)) {
            if (is_bool($value) && $type === 'tinyint') $value = (int)$value;
            if (!preg_match('/^-?\d+$/D', (string)$value)) self::invalid('Nilai integer backup tidak valid.');
            $bits = ['tinyint'=>8,'smallint'=>16,'mediumint'=>24,'int'=>32,'bigint'=>64][$type];
            $unsigned = str_contains($column->COLUMN_TYPE, 'unsigned');
            $max = $bits === 64 ? PHP_INT_MAX : (2 ** ($unsigned ? $bits : $bits-1))-1;
            $min = $unsigned ? 0 : ($bits === 64 ? PHP_INT_MIN : -(2 ** ($bits-1)));
            if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) self::invalid('Integer backup di luar batas.');
            if ($column->COLUMN_KEY === 'PRI' && $value <= 0) self::invalid('ID backup harus positif.');
            return (int)$value;
        }
        if (in_array($type, ['decimal','float','double'], true)) {
            if (!is_numeric($value) || !is_finite((float)$value)) self::invalid('Nilai angka backup tidak valid.');
            if ($type === 'decimal' && preg_match('/decimal\((\d+),(\d+)\)/', $column->COLUMN_TYPE, $m)) {
                if (!preg_match('/^-?\d+(?:\.\d+)?$/D', (string)$value)) self::invalid('Nilai desimal backup tidak valid.');
                $parts = explode('.', ltrim((string)$value, '-'));
                if (strlen(ltrim($parts[0], '0')) > (int)$m[1]-(int)$m[2] || strlen($parts[1] ?? '') > (int)$m[2]) self::invalid('Nilai desimal backup di luar batas.');
            }
            if (str_contains($column->COLUMN_TYPE, 'unsigned') && $value < 0) self::invalid('Nilai backup tidak boleh negatif.');
            return $value;
        }
        if (in_array($type, ['datetime','timestamp','date'], true)) {
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!'.$format, $value) : false;
            if (!$date || $date->format($format) !== $value) self::invalid('Tanggal backup tidak valid.');
            if ($type === 'timestamp' && ($value < '1970-01-01 00:00:01' || $value > '2038-01-19 03:14:07')) self::invalid('Timestamp backup di luar batas.');
            return $value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) self::invalid('Teks backup tidak valid.');
        if ($column->CHARACTER_MAXIMUM_LENGTH !== null && mb_strlen($value) > (int)$column->CHARACTER_MAXIMUM_LENGTH) self::invalid('Teks backup melebihi batas kolom.');
        if ($column->CHARACTER_OCTET_LENGTH !== null && strlen($value) > (int)$column->CHARACTER_OCTET_LENGTH) self::invalid('Teks backup melebihi batas byte kolom.');
        if ($type === 'enum') {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $column->COLUMN_TYPE, $m);
            if (!in_array($value, array_map('stripcslashes', $m[1]), true)) self::invalid('Pilihan enum backup tidak valid.');
        }
        return $value;
    }

    public static function invalid(string $message): void
    {
        throw ValidationException::withMessages(['backup' => $message]);
    }
}
