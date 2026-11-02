<?php

namespace App\Models;



class TransaksiTransfer extends BaseModel
{
    protected $table = 'transaksi_transfer';
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'penyedia', 'jenis_transfer', 'bank_tujuan', 'nomor_rekening_tujuan',
        'nama_penerima', 'jenis_nasabah', 'jenis_kartu', 'nominal_transfer', 'biaya_admin', 'total_bayar',
        'keterangan', 'status', 'kasir'
    ];
    protected $casts = ['tanggal' => 'date'];
}
