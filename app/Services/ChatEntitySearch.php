<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\MessageEntityLink;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Typeahead for the composer's @-attach picker.
 *
 * Every type is gated on the permission that guards its own screen. Without
 * that, the picker becomes a way to enumerate customers, contacts or tickets
 * for a staff member who cannot open any of those pages — a search box is not
 * a lesser kind of read.
 */
class ChatEntitySearch
{
    /** Results per type. Enough to choose from, small enough to stay instant. */
    public const LIMIT = 8;

    /** Type key => the permission that guards it. */
    public const PERMISSIONS = [
        'product' => 'products.view',
        'customer' => 'customers.view',
        'contact' => 'customers.view',
        'ticket' => 'tickets.view',
    ];

    public function permissionFor(string $type): ?string
    {
        return self::PERMISSIONS[$type] ?? null;
    }

    public function allows(User $user, string $type): bool
    {
        $permission = $this->permissionFor($type);

        return $permission !== null && $user->hasPermission($permission);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(User $user, string $term, ?string $type = null): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $types = $type !== null
            ? array_intersect([$type], array_keys(self::PERMISSIONS))
            : array_keys(self::PERMISSIONS);

        $results = [];

        foreach ($types as $candidate) {
            if (! $this->allows($user, $candidate)) {
                continue;
            }

            $results = array_merge($results, $this->searchType($candidate, $term));
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchType(string $type, string $term): array
    {
        $like = '%'.$term.'%';

        return match ($type) {
            'product' => Product::query()
                ->where('name', 'like', $like)
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Product $p) => $this->row('product', $p->id, $p->name, null))
                ->all(),

            'customer' => Customer::query()
                ->with('user')
                ->where(function ($q) use ($like) {
                    $q->where('company', 'like', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like));
                })
                ->orderBy('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Customer $c) => $this->row('customer', $c->id, $c->full_name, $c->company))
                ->all(),

            'contact' => CustomerContact::query()
                ->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('id')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (CustomerContact $c) => $this->row(
                    'contact',
                    $c->id,
                    trim($c->first_name.' '.$c->last_name),
                    $c->email,
                ))
                ->all(),

            'ticket' => Ticket::query()
                ->where(function ($q) use ($like) {
                    $q->where('ticket_no', 'like', $like)->orWhere('subject', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get(['id', 'ticket_no', 'subject'])
                ->map(fn (Ticket $t) => $this->row('ticket', $t->id, $t->ticket_no, $t->subject))
                ->all(),

            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $type, int $id, string $label, ?string $subtitle): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'label' => $label !== '' ? $label : '#'.$id,
            'subtitle' => $subtitle,
            'class' => MessageEntityLink::classFor($type),
        ];
    }

    /**
     * Everything the client may search, with the caller's access already
     * applied — so the picker only offers tabs the user can actually use.
     *
     * @return Collection<int, string>
     */
    public function availableTypes(User $user): Collection
    {
        return collect(array_keys(self::PERMISSIONS))
            ->filter(fn (string $type) => $this->allows($user, $type))
            ->values();
    }
}
