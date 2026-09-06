<?php

namespace App\Support;

use App\Models\TicketDepartment;
use App\Settings\EmailSettings;
use Illuminate\Support\Facades\Log;

/**
 * One inbox per support department that has its own IMAP mailbox.
 *
 * Department-only since global Incoming Mail was removed — every mailbox
 * belongs to a department, which keeps routing unambiguous (which desk the mail
 * arrived at) and removes the shared-inbox duplicate-import trap.
 */
final class MailboxConfig
{
    public function __construct(
        /** Human label for command output and log lines. */
        public readonly string $label,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly bool $validateCert,
        public readonly string $username,
        public readonly string $password,
        public readonly string $folder,
        public readonly bool $deleteAfterFetch,
        /** Department this inbox belongs to, or null for the global mailbox. */
        public readonly ?string $departmentSlug = null,
    ) {}

    /**
     * Every inbox the fetcher should poll — one per enabled department that
     * has a mailbox configured. De-duplicated by host:port:user:folder so two
     * departments accidentally pointed at the same inbox don't import twice.
     *
     * @param  string|null|object  $onlyDepartment  restrict to one department slug; legacy EmailSettings as first arg is ignored for BC
     * @param  string|null  $legacyDepartment
     * @return list<self>
     */
    public static function listForFetch(mixed $a = null, mixed $b = null, mixed $c = null): array
    {
        // BC: old signature was listForFetch(EmailSettings $settings, ?string $onlyDepartment, bool $force)
        // new is listForFetch(?string $onlyDepartment). Handle both.
        $onlyDepartment = null;
        if ($a instanceof EmailSettings) {
            $onlyDepartment = $b;
        } elseif (is_string($a) || $a === null) {
            $onlyDepartment = $a;
            // if called as listForFetch('sales') where $a is string, $b is null — correct
            // if called as listForFetch($settings, 'sales'), $a is object, handled above
        }
        $only = trim((string) $onlyDepartment);
        $mailboxes = [];
        $seen = [];

        $add = function (self $mailbox) use (&$mailboxes, &$seen): void {
            if ($mailbox->host === '') {
                return;
            }
            if (isset($seen[$mailbox->key()])) {
                Log::warning('Mailbox duplicate suppressed — already polling same host:port:user:folder.', [
                    'suppressed' => $mailbox->label,
                    'key' => $mailbox->key(),
                    'username' => $mailbox->username,
                    'host' => $mailbox->host,
                ]);

                return;
            }

            $seen[$mailbox->key()] = true;
            $mailboxes[] = $mailbox;
        };

        TicketDepartment::query()
            ->enabled()
            ->withMailbox()
            ->when($only !== '', fn ($query) => $query->where('slug', $only))
            ->ordered()
            ->get()
            ->each(function (TicketDepartment $department) use ($add): void {
                // One unreadable department must not cost every other desk its
                // inbound mail. Building a config reads the stored credential,
                // and anything that throws there used to escape all the way out
                // of tickets:fetch-mail, so a single bad row stopped ALL
                // inbound ticket mail rather than just its own.
                try {
                    $add(self::fromDepartment($department));
                } catch (\Throwable $e) {
                    Log::error('Mailbox skipped — its configuration could not be read.', [
                        'department' => $department->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $mailboxes;
    }

    public static function fromDepartment(TicketDepartment $department): self
    {
        return new self(
            label: $department->name.' mailbox',
            host: trim((string) $department->imap_host),
            port: $department->imap_port > 0 ? $department->imap_port : 993,
            encryption: (string) $department->imap_encryption,
            validateCert: (bool) $department->imap_validate_cert,
            username: (string) $department->imap_username,
            password: (string) $department->imap_password,
            folder: trim((string) $department->imap_folder) !== '' ? trim((string) $department->imap_folder) : 'INBOX',
            deleteAfterFetch: (bool) $department->imap_delete_after_fetch,
            departmentSlug: $department->slug,
        );
    }

    /**
     * Connection array for webklex's ClientManager.
     *
     * @return array<string, mixed>
     */
    public function toClientConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => 'imap',
            // webklex expects false, not "none", to disable transport security.
            'encryption' => in_array($this->encryption, ['ssl', 'tls'], true) ? $this->encryption : false,
            'validate_cert' => $this->validateCert,
            'username' => $this->username,
            'password' => $this->password,
            'authentication' => null,
        ];
    }

    /**
     * Identity used to skip an inbox already polled in this run — two
     * departments pointed at the same inbox would otherwise import every
     * message twice.
     */
    public function key(): string
    {
        return strtolower($this->host).':'.$this->port.':'.strtolower($this->username).':'.strtolower($this->folder);
    }
}
