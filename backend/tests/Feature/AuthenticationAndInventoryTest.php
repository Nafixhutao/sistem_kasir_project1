<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationAndInventoryTest extends TestCase
{
    public function test_login_and_barang_crud_import_search_preserve_string_codes(): void
    {
        $username = 'codex_'.Str::lower(Str::random(10));
        User::create([
            'name' => 'Codex Test Admin',
            'username' => $username,
            'password' => Hash::make('admin123'),
        ]);

        $login = $this->postJson('/api/login', ['username' => $username, 'password' => 'admin123'])
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'username'], 'token']);
        $token = $login->json('token');

        $code = '000'.Str::upper(Str::random(7));
        $headers = ['Authorization' => 'Bearer '.$token];
        $created = $this->withHeaders($headers)->postJson('/api/barang', [
            'kode_barang' => $code,
            'nama_barang' => 'Barang Pengujian',
            'kategori' => 'Aksesoris',
            'stok' => 10,
            'harga_beli' => 10000,
            'harga_jual' => 15000,
        ])->assertCreated()->assertJsonPath('kode_barang', $code);
        $id = $created->json('id');

        $this->withHeaders($headers)->getJson('/api/barang-search?q='.urlencode($code))
            ->assertOk()->assertJsonFragment(['kode_barang' => $code]);
        $this->withHeaders($headers)->putJson('/api/barang/'.$id, [
            'kode_barang' => $code,
            'nama_barang' => 'Barang Pengujian Diperbarui',
            'kategori' => 'Aksesoris',
            'stok' => 12,
            'harga_beli' => 10000,
            'harga_jual' => 16000,
        ])->assertOk()->assertJsonPath('nama_barang', 'Barang Pengujian Diperbarui');

        $importCode = '000'.Str::upper(Str::random(8));
        $this->withHeaders($headers)->postJson('/api/barang/import', ['items' => [[
            'kode_barang' => $importCode,
            'nama_barang' => 'Barang Import',
            'kategori' => 'Kartu',
            'stok' => 3,
            'harga_beli' => 5000,
            'harga_jual' => 7000,
        ]]])->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('barang', ['kode_barang' => $importCode]);

        $this->withHeaders($headers)->postJson('/api/barang', [
            'kode_barang' => str_repeat('X', 51),
            'nama_barang' => str_repeat('N', 151),
            'kategori' => str_repeat('K', 101),
            'stok' => 1,
            'harga_beli' => 1,
            'harga_jual' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['kode_barang', 'nama_barang', 'kategori']);

        $this->withHeaders($headers)->postJson('/api/barang/import', ['items' => [[
            'kode_barang' => str_repeat('I', 51),
            'nama_barang' => str_repeat('N', 151),
            'kategori' => str_repeat('K', 101),
            'stok' => 1,
            'harga_beli' => 1,
            'harga_jual' => 1,
        ]]])->assertUnprocessable()->assertJsonValidationErrors([
            'items.0.kode_barang', 'items.0.nama_barang', 'items.0.kategori',
        ]);

        $this->withHeaders($headers)->deleteJson('/api/barang/'.$id)->assertOk();
        $this->withHeaders($headers)->postJson('/api/logout')->assertOk();
    }
}
