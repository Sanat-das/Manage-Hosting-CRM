<?php

namespace App\Settings\Casts;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelSettings\SettingsCasts\SettingsCast;

/**
 * Encrypts a settings value at rest (Crypt::encryptString) and decrypts it on
 * read. Empty values are stored as-is — no ciphertext is produced for blanks,
 * so a blank field never yields a decrypt error.
 *
 * A payload that is NOT valid ciphertext is handed back verbatim instead of
 * throwing. This matters because the failure is not local: spatie hydrates a
 * whole group at once, so one plaintext row makes app(EmailSettings::class)
 * throw, and every caller of that group degrades — MailSettings::apply()
 * swallows it and silently falls back to the .env mailer, so outbound mail
 * quietly stops using the configured server. Plaintext gets into the table
 * through an older dump, a seeder, an APP_KEY rotation, or any direct
 * DB::table('settings_properties')->update() (which bypasses casts entirely).
 *
 * Nothing is weakened: the value is already in the database in the clear, and
 * the write side still always encrypts, so the next save heals the row. Same
 * reasoning and same trade as App\Casts\EncryptedOrPlaintext, which was added
 * after a single plaintext imap_password stopped inbound ticket mail for every
 * department.
 */
class EncryptedCast implements SettingsCast
{
    public function get($payload)
    {
        if (! $payload) {
            return '';
        }

        try {
            return Crypt::decryptString($payload);
        } catch (\Throwable) {
            Log::warning('A settings value is not encrypted; reading it as plaintext. Re-save the settings tab to encrypt it.');

            return (string) $payload;
        }
    }

    public function set($payload)
    {
        return $payload ? Crypt::encryptString($payload) : '';
    }
}
