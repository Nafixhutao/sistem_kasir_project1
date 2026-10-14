<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Supplier extends Model
{
    protected $fillable = ['nama_supplier', 'no_telepon', 'alamat', 'keterangan'];
    public function barang()
    {
        return $this->hasMany(Barang::class, 'supplier_id');
    }
}
