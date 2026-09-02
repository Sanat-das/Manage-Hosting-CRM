<?php

namespace App\Support;

use App\Models\TicketDepartment;
use App\Settings\EmailSettings;

/**
 * One inbox for the ticket fetcher to poll, from either source: the global
 * Settings > Email > Incoming Mail configuration, or a support department that
 * has its own.
 *
 * Exists so FetchTicketMailCommand has a single shape to loop over and does not
 * branch on where the credentials came from.
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
     * Every inbox the fetcher should poll: departments carrying their own,
     * plus the global mailbox, minus duplicates.
     *
     * Departments are collected first on purpose, so a department pointing at
     * the same inbox as the global settings wins the de-duplication and keeps
     * its own label. Sharing an inbox is otherwise the WHMCS duplicate-import
     * trap — both configurations would read every message.
     *
     * @param  string|null  $onlyDepartment  restrict to one department slug; the global mailbox is then skipped
     * @param  bool  $force  include the global mailbox even when Incoming Mail is switched off
     * @return list<self>
     */
    public static function listForFetch(EmailSettings $settings, ?string $onlyDepartment = null, bool $force = false): array
    {
        $only = trim((string) $onlyDepartment);
        $mailboxes = [];
        $seen = [];

        $add = function (self $mailbox) use (&$mailboxes, &$seen): void {
            if ($mailbox->host === '' || isset($seen[$mailbox->key()])) {
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
            ->each(fn (TicketDepartment $department) => $add(self::fromDepartment($department)));

        if ($only === '' && ($settings->imap_enabled || $force)) {
            $add(self::fromSettings($settings));
        }

        return $mailboxes;
    }

    public static function fromSettings(EmailSettings $settings): self
    {
        return new self(
            label: 'Global mailbox',
            host: trim($settings->imap_host),
            port: $settings->imap_port > 0 ? $settings->imap_port : 993,
            encryption: $settings->imap_encryption,
            validateCert: $settings->imap_validate_cert,
            username: (string) $settings->imap_username,
            password: (string) $settings->imap_password,
            folder: trim($settings->imap_folder) !== '' ? trim($settings->imap_folder) : 'INBOX',
            deleteAfterFetch: $settings->imap_delete_after_fetch,
        );
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
     * Identity used to skip an inbox already polled in this run — a department
     * pointed at the same inbox as the global mailbox would otherwise import
     * every message twice.
     */
    public function key(): string
    {
        return strtolower($this->host).':'.$this->port.':'.strtolower($this->username).':'.strtolower($this->folder);
    }
}
