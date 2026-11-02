<?php
namespace App\Models;

class Supplier extends BaseModel
{
    protected $fillable = ['nama_supplier', 'no_telepon', 'alamat', 'keterangan'];
    public function barang()
    {
        return $this->hasMany(Barang::class, 'supplier_id');
    }
}
