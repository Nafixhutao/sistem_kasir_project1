<?php

namespace App\Models;



class TransaksiTarikTunai extends BaseModel
{
    protected $table = 'transaksi_tarik_tunai';
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'penyedia', 'nomor_rekening', 'nama_penerima',
        'nomor_hp', 'jenis_nasabah', 'jenis_kartu', 'nominal_tarik', 'biaya_admin', 'total_bayar',
        'status', 'kasir'
    ];
    protected $casts = ['tanggal' => 'date'];
}
