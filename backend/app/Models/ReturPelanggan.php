<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturPelanggan extends Model
{
    protected $table = 'retur_pelanggan';
    protected $hidden = ['request_hash'];

    protected $fillable = [
        'nomor_retur',
        'transaksi_penjualan_id',
        'detail_penjualan_id',
        'barang_id',
        'kode_transaksi',
        'kode_barang',
        'nama_barang',
        'jumlah_dibeli',
        'jumlah_retur',
        'alasan_retur',
        'metode_pengembalian_dana',
        'keterangan',
        'tanggal_retur',
    ];

    protected $casts = [
        'tanggal_retur' => 'date',
    ];

    public function transaksi()
    {
        return $this->belongsTo(TransaksiPenjualan::class, 'transaksi_penjualan_id');
    }

    public function detail()
    {
        return $this->belongsTo(DetailPenjualan::class, 'detail_penjualan_id');
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }
}
