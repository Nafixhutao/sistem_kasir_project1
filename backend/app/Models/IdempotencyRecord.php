<?php
namespace App\Models;



class IdempotencyRecord extends BaseModel
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
