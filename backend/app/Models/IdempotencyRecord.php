<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    public const SCOPES=[
        'penjualan'=>TransaksiPenjualan::class,
        'retur_pelanggan'=>ReturPelanggan::class,
        'retur_supplier'=>ReturSupplier::class,
    ];
    protected $fillable=['scope','request_id','request_hash','status','result_id'];
    protected $hidden=['request_hash'];
    protected $casts=['result_id'=>'integer'];
}
