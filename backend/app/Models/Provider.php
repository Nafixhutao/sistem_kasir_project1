<?php

namespace App\Models;



class Provider extends BaseModel
{
    protected $fillable = [
        'nama_provider',
        'aktif',
    ];

    protected $casts = [
        'aktif' => 'boolean',
    ];

    public const DEFAULT_PROVIDERS = [
        'BRILink Mobile',
        'BRIMO Agen',
        'Fastpay',
        'Payfazz',
        'Mitra Bukalapak',
        'Digipos',
        'Kiosbank',
        'Finnet',
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULT_PROVIDERS as $provider) {
            self::updateOrCreate(
                ['nama_provider' => $provider],
                ['aktif' => true]
            );
        }
    }
}
