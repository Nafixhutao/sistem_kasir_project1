<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * CAPTCHA teks buatan sendiri (tanpa layanan pihak ketiga).
 *
 * Server membuat 5 karakter acak, menggambarnya menjadi PNG yang diberi garis
 * dan titik pengganggu, lalu mengirim gambar + token bertanda tangan (HMAC
 * dengan APP_KEY). Jawaban tidak pernah dikirim ke browser dalam bentuk teks,
 * hanya tersembunyi di dalam token yang hanya bisa dibuka server.
 */
final class Captcha
{
    private const TTL = 300; // detik
    private const LENGTH = 5;

    // Tanpa 0/O/1/I agar tidak salah baca.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{token:string,image:string}
     */
    public static function make(): array
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $payload = self::base64UrlEncode((string) json_encode([
            'c' => $code,
            'e' => time() + self::TTL,
        ]));

        return [
            'token' => $payload . '.' . self::sign($payload),
            'image' => self::render($code),
        ];
    }

    /**
     * Memeriksa jawaban pengguna. Token hanya boleh dipakai satu kali.
     */
    public static function verify(?string $token, ?string $answer): bool
    {
        if (!is_string($token) || $token === '' || !is_string($answer) || trim($answer) === '') {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$payload, $signature] = $parts;

        if (!hash_equals(self::sign($payload), $signature)) {
            return false;
        }

        $data = json_decode(self::base64UrlDecode($payload) ?? '', true);
        if (!is_array($data) || !isset($data['c'], $data['e']) || (int) $data['e'] < time()) {
            return false;
        }

        $usedKey = 'captcha:used:' . hash('sha256', $token);
        if (Cache::has($usedKey)) {
            return false;
        }

        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $answer));
        if (!hash_equals((string) $data['c'], $normalized)) {
            return false;
        }

        Cache::put($usedKey, true, self::TTL);

        return true;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Menggambar kode menjadi PNG (data URI).
     *
     * Container tidak menyertakan font TTF, jadi tiap karakter digambar di kanvas
     * kecil (font bawaan GD), diputar sedikit, lalu diperbesar ke kanvas utama.
     * Hasilnya huruf besar yang tidak seragam posisinya — tetap terbaca manusia,
     * sulit dibaca program pembaca otomatis.
     */
    private static function render(string $code): string
    {
        $width = 320;
        $height = 104;

        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 247, 251, 255));

        // Titik-titik halus sebagai tekstur latar.
        for ($i = 0; $i < 650; $i++) {
            imagesetpixel(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                imagecolorallocate($image, random_int(195, 230), random_int(210, 240), random_int(228, 252))
            );
        }

        // Garis pengganggu di belakang huruf.
        for ($i = 0; $i < 4; $i++) {
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                imagecolorallocate($image, random_int(165, 205), random_int(188, 222), random_int(215, 245))
            );
        }

        $font = resource_path('fonts/captcha-bold.ttf');
        $hasFont = is_file($font);

        $x = 26;
        foreach (str_split($code) as $character) {
            $ink = imagecolorallocate($image, random_int(15, 55), random_int(40, 90), random_int(100, 165));

            if ($hasFont) {
                // Huruf digambar di kanvas kecil lalu diputar, supaya posisi & kemiringan
                // tiap karakter berbeda dan sulit dibaca program.
                $glyph = imagecreatetruecolor(90, 90);
                imagealphablending($glyph, false);
                imagesavealpha($glyph, true);
                imagefilledrectangle($glyph, 0, 0, 90, 90, imagecolorallocatealpha($glyph, 0, 0, 0, 127));
                imagealphablending($glyph, true);

                imagettftext($glyph, 30, 0, 14, 60, $ink, $font, $character);

                $rotated = imagerotate($glyph, random_int(-22, 22), imagecolorallocatealpha($glyph, 0, 0, 0, 127));
                imagedestroy($glyph);

                $targetWidth = (int) round(imagesx($rotated) * 0.82);
                $targetHeight = (int) round(imagesy($rotated) * 0.82);

                imagecopyresampled(
                    $image,
                    $rotated,
                    $x,
                    random_int(0, 10),
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    imagesx($rotated),
                    imagesy($rotated)
                );
                imagedestroy($rotated);
            } else {
                imagestring($image, 5, $x, random_int(28, 46), $character, $ink);
            }

            $x += 56;
        }

        // Garis tipis menembus huruf supaya tidak mudah dipotong program.
        for ($i = 0; $i < 2; $i++) {
            imageline(
                $image,
                random_int(0, 30),
                random_int(28, 78),
                random_int(270, $width),
                random_int(28, 78),
                imagecolorallocate($image, random_int(150, 190), random_int(172, 212), random_int(205, 240))
            );
        }

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
