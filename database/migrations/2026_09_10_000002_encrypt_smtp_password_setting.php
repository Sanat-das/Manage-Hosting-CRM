<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts the stored email.smtp_password payload now that EmailSettings casts
 * it through EncryptedCast.
 *
 * It was the only live credential on the settings page held in clear text —
 * IntegrationSettings has encrypted its three secrets since T4.2.
 *
 * Deliberately tolerant in both directions: the value may already be
 * ciphertext (this migration re-run, or a database seeded after the cast
 * landed), may be blank, or may be plaintext. Only plaintext is touched.
 * Nothing here may throw — a settings migration that fails mid-way leaves the
 * group half-cast, and spatie hydrates a group all at once.
 */
return new class extends Migration
{
    private const GROUP = 'email';

    private const NAME = 'smtp_password';

    public function up(): void
    {
        $this->rewrite(function (string $plain): ?string {
            if ($this->isEncrypted($plain)) {
                return null; // already done
            }

            return Crypt::encryptString($plain);
        });
    }

    /**
     * Rolling back means the code reading this row expects clear text again, so
     * decrypt rather than leaving an unreadable payload behind.
     */
    public function down(): void
    {
        $this->rewrite(function (string $stored): ?string {
            if (! $this->isEncrypted($stored)) {
                return null;
            }

            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * @param  callable(string): ?string  $transform  null = leave the row alone
     */
    private function rewrite(callable $transform): void
    {
        if (! Schema::hasTable('settings_properties')) {
            return;
        }

        $row = DB::table('settings_properties')
            ->where('group', self::GROUP)
            ->where('name', self::NAME)
            ->first();

        if ($row === null) {
            return;
        }

        $value = json_decode((string) $row->payload, true);

        // Blank, null or a non-string payload: nothing to protect.
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $next = $transform($value);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($next === null) {
            return;
        }

        DB::table('settings_properties')
            ->where('group', self::GROUP)
            ->where('name', self::NAME)
            ->update(['payload' => json_encode($next), 'updated_at' => now()]);
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
