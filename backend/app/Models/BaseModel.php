<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model dasar untuk seluruh tabel aplikasi.
 *
 * Nama kolom waktu di database memakai bahasa Indonesia, jadi setiap model
 * perlu memberi tahu Eloquent nama kolom yang dipakai.
 */
abstract class BaseModel extends Model
{
    public const CREATED_AT = 'dibuat_pada';
    public const UPDATED_AT = 'diperbarui_pada';
}
