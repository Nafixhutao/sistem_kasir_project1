<?php

namespace App\Models;



class Notifikasi extends BaseModel
{
    protected $table = 'notifikasi';

    protected $fillable = [
        'user_id',
        'tipe',
        'judul',
        'pesan',
        'referensi_tipe',
        'referensi_id',
        'url',
        'fingerprint',
        'dibaca_at',
    ];

    protected $casts = [
        'dibaca_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
