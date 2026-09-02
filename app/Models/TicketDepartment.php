<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support department: the address customers write to, and the mailbox their
 * replies come back through.
 *
 * `slug` is the join key, not `id` — tickets.department has always stored the
 * slug and continues to, so existing tickets keep working and a department can
 * be renamed without touching them.
 */
#[Fillable([
    'name', 'slug', 'email_address', 'description', 'signature', 'is_default',
    'enabled', 'allow_new_tickets', 'sort_order',
    'imap_enabled', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username',
    'imap_password', 'imap_folder', 'imap_validate_cert', 'imap_delete_after_fetch',
])]
class TicketDepartment extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allow_new_tickets' => 'boolean',
            'sort_order' => 'integer',
            'imap_enabled' => 'boolean',
            'imap_port' => 'integer',
            'imap_validate_cert' => 'boolean',
            'imap_delete_after_fetch' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'department', 'slug');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_department_user')->withTimestamps();
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Departments the fetch command should poll: switched on, and carrying
     * their own host. Anything else falls back to the global Incoming Mail
     * settings, which is what a single-mailbox install uses.
     */
    public function scopeWithMailbox(Builder $query): Builder
    {
        return $query->where('imap_enabled', true)->whereNotNull('imap_host')->where('imap_host', '!=', '');
    }

    public function hasMailbox(): bool
    {
        return $this->imap_enabled && trim((string) $this->imap_host) !== '';
    }

    /**
     * Identity of the mailbox this department reads, used to catch two
     * departments pointing at the same inbox — WHMCS's documented cause of
     * every reply being imported twice.
     */
    public function mailboxKey(): ?string
    {
        if (! $this->hasMailbox()) {
            return null;
        }

        return strtolower(trim((string) $this->imap_host)).':'.$this->imap_port
            .':'.strtolower(trim((string) $this->imap_username))
            .':'.strtolower(trim((string) ($this->imap_folder ?: 'INBOX')));
    }
}
