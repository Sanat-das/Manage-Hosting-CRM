<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A chat message referencing a domain entity — attached in the composer and
 * rendered as a card in the message, and listed back on the entity's own page.
 *
 * The table has only created_at, so UPDATED_AT is switched off rather than
 * Eloquent writing to a column that does not exist.
 */
#[Fillable(['message_id', 'linkable_type', 'linkable_id'])]
class MessageEntityLink extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The only types that may be linked. Anything outside this map is rejected
     * before it reaches the database — a polymorphic column with no whitelist
     * is an arbitrary-class-load surface.
     *
     * Keyed by the short name used in the API and the composer UI.
     */
    public const LINKABLE_TYPES = [
        'product' => Product::class,
        'customer' => Customer::class,
        'contact' => CustomerContact::class,
        'ticket' => Ticket::class,
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatConversationMessage::class, 'message_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The short key ("product", "ticket", …) for this link's class, or null if
     * the row somehow holds a type outside the whitelist.
     */
    public function typeKey(): ?string
    {
        $key = array_search($this->linkable_type, self::LINKABLE_TYPES, true);

        return $key === false ? null : $key;
    }

    public static function classFor(string $typeKey): ?string
    {
        return self::LINKABLE_TYPES[$typeKey] ?? null;
    }

    /**
     * What the card in the message says.
     *
     * A link whose target has since been deleted renders as a tombstone rather
     * than disappearing: the message said something about it, and silently
     * dropping the reference rewrites history.
     */
    public function label(): string
    {
        $entity = $this->linkable;

        if ($entity === null) {
            return '(deleted)';
        }

        return match ($this->typeKey()) {
            'product' => (string) $entity->name,
            'customer' => (string) $entity->full_name,
            'contact' => trim($entity->first_name.' '.$entity->last_name),
            'ticket' => (string) $entity->ticket_no,
            default => '#'.$this->linkable_id,
        };
    }

    /**
     * Where the card points. Null when the target is gone, or when the type is
     * not one with an admin page.
     */
    public function url(): ?string
    {
        if ($this->linkable === null) {
            return null;
        }

        return match ($this->typeKey()) {
            'product' => route('admin.products.show', $this->linkable_id),
            'customer' => route('admin.customers.show', $this->linkable_id),
            'ticket' => route('admin.tickets.show', $this->linkable_id),
            // Contacts have no page of their own; they live on the customer's.
            'contact' => $this->linkable->customer_id === null
                ? null
                : route('admin.customers.show', $this->linkable->customer_id),
            default => null,
        };
    }
}
