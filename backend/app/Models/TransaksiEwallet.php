<?php

namespace App\Models;



class TransaksiEwallet extends BaseModel
{
    protected $table = 'transaksi_ewallet';
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'jenis_transaksi', 'penyedia', 'jenis_ewallet',
        'nomor_ewallet', 'nama_customer', 'nominal', 'biaya_admin',
        'total_bayar', 'keterangan', 'status', 'kasir',
    ];
}
