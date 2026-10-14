<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturSupplier extends Model
{
    use HasFactory;

    protected $table = 'retur_supplier';
    protected $hidden = ['request_hash'];

    protected $fillable = [
        'nomor_retur',
        'tanggal_retur',
        'nama_supplier',
        'barang_id',
        'kode_barang',
        'nama_barang',
        'jumlah_retur',
        'alasan_retur',
        'status_retur',
        'keterangan',
        'stok_dikurangi',
        'kasir',
    ];

    protected $casts = [
        'tanggal_retur' => 'date',
        'stok_dikurangi' => 'boolean',
    ];

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }
}
