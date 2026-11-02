<?php

namespace App\Models;



class ActivityLog extends BaseModel
{
    protected $fillable = [
        'nama_pengguna', 'modul', 'aksi', 'keterangan', 'properti',
    ];

    protected $casts = [
        'properti' => 'array',
    ];

    public static function record(string $modul, string $aksi, ?string $keterangan = null, array $properties = []): void
    {
        try {
            self::create([
                'nama_pengguna' => request()->is('api/midtrans/notification') ? 'midtrans' : (auth()->user()?->username ?? 'system'),
                'modul' => $modul,
                'aksi' => $aksi,
                'keterangan' => $keterangan,
                'properti' => $properties,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
