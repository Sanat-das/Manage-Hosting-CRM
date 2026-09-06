<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt the IMAP passwords already sitting in `ticket_departments`.
 *
 * `TicketDepartment` now casts `imap_password` to `encrypted`, so every value
 * written from here on is ciphertext. The rows stored before that cast existed
 * are still plaintext, and the cast would try to decrypt them on read and throw
 * `DecryptException`, taking the mailbox list — and therefore inbound ticket
 * mail — down with it.
 *
 * Values are read and written through the query builder rather than the model
 * so the cast does not fire while we are the ones doing the encrypting.
 * Already-encrypted values are detected and skipped, which is what makes this
 * safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ticket_departments')
            ->select('id', 'imap_password')
            ->whereNotNull('imap_password')
            ->where('imap_password', '!=', '')
            ->orderBy('id')
            ->each(function (object $row): void {
                if ($this->isEncrypted((string) $row->imap_password)) {
                    return;
                }

                DB::table('ticket_departments')
                    ->where('id', $row->id)
                    ->update(['imap_password' => Crypt::encryptString((string) $row->imap_password)]);
            });
    }

    /**
     * Back to plaintext, so rolling the migration back does not leave the
     * application unable to read its own mailbox credentials.
     */
    public function down(): void
    {
        DB::table('ticket_departments')
            ->select('id', 'imap_password')
            ->whereNotNull('imap_password')
            ->where('imap_password', '!=', '')
            ->orderBy('id')
            ->each(function (object $row): void {
                if (! $this->isEncrypted((string) $row->imap_password)) {
                    return;
                }

                DB::table('ticket_departments')
                    ->where('id', $row->id)
                    ->update(['imap_password' => Crypt::decryptString((string) $row->imap_password)]);
            });
    }

    /**
     * Laravel ciphertext is base64-encoded JSON carrying iv/value/mac. A
     * successful decrypt is the only reliable test — a plaintext password
     * could in principle look like base64, but it will not carry a valid MAC.
     */
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
