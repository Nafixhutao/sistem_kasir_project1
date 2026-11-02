<?php

namespace App\Models;



class PembayaranTagihan extends BaseModel
{
    protected $table = 'pembayaran_tagihan';
    protected $fillable = [
        'kode_transaksi', 'tanggal', 'penyedia', 'jenis_layanan', 'nomor_pelanggan',
        'nama_pelanggan', 'jenis_nasabah', 'jenis_kartu', 'jumlah_tagihan', 'biaya_admin', 'total_bayar',
        'status', 'kasir'
    ];
    protected $casts = ['tanggal' => 'date'];
}
