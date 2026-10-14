<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReturSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => 'required|uuid',
            'tanggal_retur' => 'required|date',
            'nama_supplier' => 'required|string|max:150',
            'barang_id' => 'required|integer|min:1|max:4294967295|exists:barang,id',
            'jumlah_retur' => 'required|integer|min:1',
            'alasan_retur' => 'required|string|min:3',
            'keterangan' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal_retur.required' => 'Tanggal retur wajib diisi.',
            'nama_supplier.required' => 'Nama supplier wajib diisi.',
            'barang_id.required' => 'Barang wajib dipilih.',
            'barang_id.exists' => 'Barang tidak ditemukan.',
            'jumlah_retur.required' => 'Jumlah retur wajib diisi.',
            'jumlah_retur.integer' => 'Jumlah retur harus berupa angka.',
            'jumlah_retur.min' => 'Jumlah retur wajib lebih dari 0.',
            'alasan_retur.required' => 'Alasan retur wajib diisi.',
            'alasan_retur.min' => 'Alasan retur minimal 3 karakter.',
        ];
    }
}
