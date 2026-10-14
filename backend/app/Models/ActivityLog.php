<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_name', 'module', 'action', 'description', 'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public static function record(string $module, string $action, ?string $description = null, array $properties = []): void
    {
        try {
            self::create([
                'user_name' => request()->is('api/midtrans/notification') ? 'midtrans' : (auth()->user()?->username ?? 'system'),
                'module' => $module,
                'action' => $action,
                'description' => $description,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
