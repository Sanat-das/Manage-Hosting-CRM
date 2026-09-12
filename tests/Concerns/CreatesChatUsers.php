<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A panel user holding exactly the permissions asked for, and no others.
 *
 * Deliberately not CreatesPanelUsers::panelUserWithPermissions(): that helper
 * puts every user it builds into one shared `test-scoped-role` and syncs the
 * requested permissions onto it, so two users created in the same test end up
 * holding the UNION of both sets. The chat tests constantly need one user with
 * chat.manage and one without, in the same test — with the shared role every
 * such denial assertion silently becomes unprovable.
 *
 * The `users.role` column is set to `marketing`, the one panel role carrying no
 * chat.*, hosting.* or tickets.* permissions, so the fallback lookup in
 * HasRoles::hasPermission() cannot hand the user anything extra either.
 */
trait CreatesChatUsers
{
    protected function chatUser(string ...$permissions): User
    {
        $user = User::factory()->create(['role' => 'marketing']);

        $role = Role::create([
            'name' => 'chat-test-'.Str::random(10),
            'label' => 'Chat Test Role',
        ]);

        if ($permissions !== []) {
            $role->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', $permissions)->pluck('id')
            );
        }

        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
