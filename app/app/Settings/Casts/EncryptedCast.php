<?php

namespace App\Settings\Casts;

use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\SettingsCasts\SettingsCast;

/**
 * Encrypts a settings value at rest (Crypt::encryptString) and decrypts it on
 * read. Empty values are stored as-is — no ciphertext is produced for blanks,
 * so a blank field never yields a decrypt error.
 */
class EncryptedCast implements SettingsCast
{
    public function get($payload)
    {
        return $payload ? Crypt::decryptString($payload) : '';
    }

    public function set($payload)
    {
        return $payload ? Crypt::encryptString($payload) : '';
    }
}
