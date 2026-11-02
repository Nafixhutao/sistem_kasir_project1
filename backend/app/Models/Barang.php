<?php

namespace App\Models;



class Barang extends BaseModel
{
    protected $table = 'barang';
    protected $fillable = [
        'kode_barang', 'nama_barang', 'kategori', 'stok', 'harga_beli', 'harga_jual', 'supplier_id'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
