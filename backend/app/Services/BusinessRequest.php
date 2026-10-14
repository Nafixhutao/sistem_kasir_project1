<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\IdempotencyRecord;

final class BusinessRequest
{
    /** One UUID identifies one operation, scoped to its endpoint and authenticated actor. */
    public static function execute(string $model, string $uuid, int $actor, array $payload, callable $create): array
    {
        $uuid=strtolower($uuid);
        $scope=array_search($model,IdempotencyRecord::SCOPES,true);
        if ($scope===false) throw new \LogicException('Unsupported idempotency scope.');
        $hash=hash('sha256',json_encode(['model'=>$model,'actor'=>$actor,'payload'=>self::canonical($payload)],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $db=DB::connection();
        // MySQL connection-scoped mutex also serializes requests when no row exists yet.
        // UNIQUE remains the final protection against writers that bypass this service.
        $lock='business:'.substr(hash('sha256',$model.':'.$uuid),0,54);
        if ((int)$db->selectOne('SELECT GET_LOCK(?, 10) AS acquired',[$lock])->acquired!==1) abort(409,'Operasi masih diproses. Coba lagi menggunakan request_id yang sama.');
        try {
            return $db->transaction(function () use ($model,$scope,$uuid,$hash,$create) {
                // The named mutex protects this UUID. Do not gap-lock a missing
                // unique key: different new UUIDs must not deadlock each other.
                $receipt=IdempotencyRecord::where('scope',$scope)->where('request_id',$uuid)->first();
                if ($receipt) {
                    if ($receipt->status!=='completed' || !hash_equals($receipt->request_hash,$hash)) abort(409,'request_id telah digunakan untuk payload atau pengguna berbeda.');
                    $existing=$model::whereKey($receipt->result_id)->where('request_id',$uuid)->first();
                    if (!$existing || !$existing->request_hash || !hash_equals($existing->request_hash,$hash)) abort(409,'Operasi sudah pernah diproses, tetapi hasilnya tidak lagi tersedia. Tidak ada operasi baru yang dijalankan.');
                    return [$existing,true];
                }
                $existing=$model::where('request_id',$uuid)->first();
                if ($existing) {
                    if (!$existing->request_hash || !hash_equals($existing->request_hash,$hash)) abort(409,'request_id telah digunakan untuk payload atau pengguna berbeda.');
                    IdempotencyRecord::create(['scope'=>$scope,'request_id'=>$uuid,'request_hash'=>$hash,'status'=>'completed','result_id'=>$existing->getKey()]);
                    return [$existing,true];
                }
                $record=$create();
                $record->forceFill(['request_id'=>$uuid,'request_hash'=>$hash])->save();
                IdempotencyRecord::create(['scope'=>$scope,'request_id'=>$uuid,'request_hash'=>$hash,'status'=>'completed','result_id'=>$record->getKey()]);
                return [$record,false];
            }, 3);
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$lock]);
        }
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=self::canonical($item);
        return $value;
    }
}
