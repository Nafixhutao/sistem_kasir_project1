<?php

namespace App\Support;

final class PasswordPolicy
{
    public static function rules(): array
    {
        return ['required', 'string', 'min:8', 'confirmed', function ($attribute, $value, $fail): void {
            if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                $fail('Password maksimal 72 byte dan tidak boleh mengandung karakter NUL.');
            }
        }];
    }
}
