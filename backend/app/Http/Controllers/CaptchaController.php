<?php

namespace App\Http\Controllers;

use App\Support\Captcha;

class CaptchaController extends Controller
{
    /**
     * Membuat CAPTCHA baru untuk halaman login / lupa password.
     */
    public function show()
    {
        $captcha = Captcha::make();

        return response()
            ->json($captcha)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
