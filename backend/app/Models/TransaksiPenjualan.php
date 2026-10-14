<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaksiPenjualan extends Model
{
    protected $table = 'transaksi_penjualan';
    protected $hidden = ['request_hash'];
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'metode_pembayaran', 'status',
        'total_harga', 'uang_bayar', 'kembalian', 'kasir',
        'alasan_batal', 'dibatalkan_pada'
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    public function details()
    {
        return $this->hasMany(DetailPenjualan::class, 'transaksi_penjualan_id');
    }

    public function pembayaranQris()
    {
        return $this->hasOne(PembayaranQris::class, 'transaksi_penjualan_id');
    }
}
