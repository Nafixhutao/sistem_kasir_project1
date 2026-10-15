<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembayaranQris extends Model
{
    protected $table = 'pembayaran_qris';
    protected $fillable = [
        'order_id', 'provider', 'transaksi_penjualan_id', 'nominal',
        'snap_token', 'payment_url', 'status_pembayaran', 'transaction_id',
        'payment_response', 'paid_at'
    ];

    protected $casts = [
        'payment_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function transaksi()
    {
        return $this->belongsTo(TransaksiPenjualan::class, 'transaksi_penjualan_id');
    }
}
