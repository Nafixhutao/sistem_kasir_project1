<?php

namespace App\Models;



class PengeluaranToko extends BaseModel
{
    protected $table = 'pengeluaran_toko';
    protected $fillable = [
        'tanggal', 'kategori', 'nama_pengeluaran', 'nominal', 'keterangan', 'kasir',
    ];
}
