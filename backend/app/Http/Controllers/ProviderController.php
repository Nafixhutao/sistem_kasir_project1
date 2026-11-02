<?php

namespace App\Http\Controllers;

use App\Models\Provider;

class ProviderController extends Controller
{
    public function index()
    {
        Provider::ensureDefaults();

        return response()->json(
            Provider::query()
                ->where('aktif', true)
                ->orderBy('nama_provider')
                ->get(['id', 'nama_provider'])
        );
    }
}
