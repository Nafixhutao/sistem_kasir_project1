<?php

namespace App\Models;



class TransaksiPulsa extends BaseModel
{
    protected $table = 'transaksi_pulsa';
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'penyedia', 'operator', 'jenis_layanan',
        'nomor_tujuan', 'produk', 'jenis_nasabah', 'jenis_kartu', 'harga', 'biaya_admin', 'total_bayar',
        'status', 'kasir'
    ];
    protected $casts = ['tanggal' => 'date'];
}
