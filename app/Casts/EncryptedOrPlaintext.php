<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * `encrypted`, but a value that is not valid ciphertext is handed back as-is
 * instead of throwing.
 *
 * The plain `encrypted` cast fails closed in the worst possible way for a live
 * mailbox credential: one row holding plaintext — written before the column was
 * encrypted, restored from an older dump, seeded, or updated through the query
 * builder (`Model::where(...)->update()` bypasses casts entirely) — makes every
 * read throw `DecryptException`. In `MailboxConfig::listForFetch()` that
 * exception is not caught, so a single bad row takes `tickets:fetch-mail` down
 * for *every* department and inbound ticket mail stops arriving anywhere.
 *
 * Reading such a value back verbatim keeps the mailbox polling and lets the
 * next save through the model re-encrypt it. Nothing is weakened by this: the
 * value is already sitting in the database in the clear, and the write side
 * still always encrypts.
 */
class EncryptedOrPlaintext implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            Log::warning('Stored value is not encrypted; reading it as plaintext. Re-save the record to encrypt it.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
            ]);

            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
