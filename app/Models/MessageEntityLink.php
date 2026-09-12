<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * The recent chat messages that reference $entity, for the timeline card on
     * that entity's admin page.
     *
     * Three things this has to get right:
     *
     *  - VISIBILITY. An entity page is a side door into chat: without a filter,
     *    a private channel's message would be readable by anyone who can open
     *    the product it mentions. Conversations are narrowed in SQL the same
     *    way ChatController's sidebar narrows them, and the ≤5 survivors are
     *    then re-checked against ChatConversationPolicy, which stays the single
     *    authority. The SQL is only a cheap pre-filter.
     *  - COST. Constant, whether the entity has five links or five thousand:
     *    the limit is applied in SQL, and the linkable, the message, its author
     *    and its conversation are all eager-loaded.
     *  - CONTACTS. A CustomerContact has no page of its own, so a message that
     *    references one surfaces on the customer it belongs to.
     *
     * @return Collection<int, self>
     */
    public static function timelineFor(Model $entity, ?User $viewer, int $limit = 5): Collection
    {
        $targets = self::timelineTargets($entity);

        if ($viewer === null || $targets === []) {
            return new Collection;
        }

        $links = self::query()
            ->where(function (Builder $query) use ($targets) {
                foreach ($targets as [$class, $ids]) {
                    $query->orWhere(
                        fn (Builder $target) => $target
                            ->where('linkable_type', $class)
                            ->whereIn('linkable_id', $ids)
                    );
                }
            })
            // whereHas also drops links whose message was soft-deleted: a
            // retracted message does not come back through the side door.
            ->whereHas(
                'message',
                fn (Builder $message) => $message->whereIn('conversation_id', self::readableConversationIds($viewer))
            )
            ->with([
                'linkable',
                'message.user',
                'message.conversation.participants.user',
                'message.conversation.customer.user',
            ])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Defence in depth, bounded by $limit: the policy gets the final say on
        // the handful the pre-filter let through.
        $decided = [];

        return $links
            ->filter(function (self $link) use ($viewer, &$decided) {
                $conversation = $link->message?->conversation;

                if ($conversation === null) {
                    return false;
                }

                return $decided[$conversation->id] ??= $viewer->can('view', $conversation);
            })
            ->values();
    }

    /**
     * Which linkable rows count as "this page", as [class, ids] pairs.
     *
     * @return array<int, array{0: class-string, 1: array<int, int>}>
     */
    private static function timelineTargets(Model $entity): array
    {
        if (! in_array($entity::class, self::LINKABLE_TYPES, true)) {
            return [];
        }

        $targets = [[$entity::class, [(int) $entity->getKey()]]];

        if ($entity instanceof Customer) {
            // Already eager-loaded by CustomerController::show(); the relation
            // property reuses it rather than querying again.
            $contactIds = $entity->contacts->pluck('id')->all();

            if ($contactIds !== []) {
                $targets[] = [CustomerContact::class, $contactIds];
            }
        }

        return $targets;
    }

    /**
     * A sub-query of the conversation ids $viewer may read, mirroring
     * ChatConversationPolicy::view() in SQL.
     */
    private static function readableConversationIds(User $viewer): Builder
    {
        $canManage = $viewer->hasPermission('chat.manage');
        $canView = $viewer->hasPermission('chat.view');

        return ChatConversation::query()
            ->select('id')
            ->where(function (Builder $query) use ($viewer, $canManage, $canView) {
                // Anything at all you are a participant of.
                $query->whereHas('participants', fn (Builder $p) => $p->where('user_id', $viewer->id));

                // Public channels, minus the departments you cannot reach.
                if ($canView) {
                    $query->orWhere(function (Builder $channel) use ($viewer, $canManage) {
                        $channel->where('type', ChatConversation::TYPE_CHANNEL)
                            ->where('is_private', false);

                        if (! $canManage) {
                            $slugs = $viewer->ticketDepartments()->pluck('slug')->all();

                            $channel->where(
                                fn (Builder $dept) => $dept
                                    ->whereNull('department')
                                    ->orWhere('department', '')
                                    ->orWhereIn('department', $slugs)
                            );
                        }
                    });
                }

                // The customer queue, for operators.
                if ($canManage) {
                    $query->orWhere('type', ChatConversation::TYPE_CUSTOMER_INBOX);
                }
            });
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
