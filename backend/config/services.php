<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'base_url' => env('MIDTRANS_BASE_URL', 'https://api.sandbox.midtrans.com'),
    ],

    'sumopod' => [
        'api_key' => env('SUMOPOD_API_KEY'),
        'base_url' => env('SUMOPOD_BASE_URL', 'https://api-pay-sandbox.sumopod.com/api/v1'),
        'webhook_token' => env('SUMOPOD_WEBHOOK_TOKEN'),
        'webhook_secret' => env('SUMOPOD_WEBHOOK_SECRET'),
        // Hanya untuk sandbox: mengizinkan kasir menandai pembayaran lunas
        // secara manual bila webhook tidak bisa dipicu dari dashboard.
        'allow_manual_confirm' => env('SUMOPOD_ALLOW_MANUAL_CONFIRM', false),
    ],

    // Provider QRIS yang dipakai halaman Transaksi Penjualan: "midtrans" atau "sumopod".
    'payment' => [
        'provider' => env('PAYMENT_PROVIDER', 'midtrans'),
    ],

];
