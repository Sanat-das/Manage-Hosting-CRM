<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a member of staff can start a conversation with.
 *
 * "Staff" here means holders of `chat.view`, not `role != 'client'` as the
 * ticket screens use. Opening a DM with somebody who cannot reach the chat
 * creates a conversation they can never read and a reply that never comes —
 * the roster has to be the people who can actually answer.
 */
class ChatDirectory
{
    /** Rows per search. Enough to pick from, short enough to scan. */
    public const LIMIT = 20;

    /** @var Collection<int, string>|null */
    private ?Collection $chatRoles = null;

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public function search(User $viewer, string $term = '', ?ChatConversation $excludeMembersOf = null): array
    {
        $roles = $this->chatRoles();

        if ($roles->isEmpty()) {
            return [];
        }

        $query = User::query()
            ->whereKeyNot($viewer->getKey())
            ->where('status', 'active')
            // Both halves of HasRoles::hasPermission(), as one query: the
            // pivot-assigned roles and the legacy `users.role` string. Checking
            // the permission per row instead would be a pair of queries per
            // candidate on every keystroke.
            ->where(function ($q) use ($roles) {
                $q->whereIn('role', $roles)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('adminlte_roles.name', $roles));
            });

        $term = trim($term);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if ($excludeMembersOf !== null) {
            $query->whereNotIn(
                'id',
                $excludeMembersOf->participants()->whereNotNull('user_id')->pluck('user_id'),
            );
        }

        return $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->full_name,
                'email' => (string) $user->email,
            ])
            ->all();
    }

    /**
     * Can this one person be given a conversation at all?
     *
     * Asked of a single named user, so it goes through the permission check
     * itself rather than the role query above — that one is a list filter, and
     * this is the authority the list is trying to approximate.
     */
    public function isChatUser(User $user): bool
    {
        return $user->status === 'active' && $user->hasPermission('chat.view');
    }

    /**
     * @return Collection<int, string>
     */
    private function chatRoles(): Collection
    {
        return $this->chatRoles ??= Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'chat.view'))
            ->pluck('name');
    }
}
