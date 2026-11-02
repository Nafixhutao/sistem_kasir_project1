<?php

namespace App\Models;



class DetailPenjualan extends BaseModel
{
    protected $table = 'detail_penjualan';
    protected $fillable = [
        'transaksi_penjualan_id', 'barang_id', 'kode_barang',
        'nama_barang', 'jumlah', 'harga_satuan', 'harga_beli_satuan', 'subtotal'
    ];

    public function transaksi()
    {
        return $this->belongsTo(TransaksiPenjualan::class, 'transaksi_penjualan_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }
}
