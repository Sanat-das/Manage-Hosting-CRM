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
 * The `users.role` column has to be one of the panel roles or AdminMiddleware
 * turns the user away at the door before any route gate is reached, so it is
 * set to `marketing` — and that role's own permissions are then emptied.
 * Without that, `HasRoles::hasPermission()` falls back to the Role named by the
 * column and quietly hands every user `customers.view` and `products.view`,
 * which made "this user cannot search customers" impossible to assert.
 * RefreshDatabase isolates the change to the test that made it.
 */
trait CreatesChatUsers
{
    /** Must be a panel role AdminMiddleware accepts. */
    private const BASELINE_ROLE = 'marketing';

    protected function chatUser(string ...$permissions): User
    {
        Role::where('name', self::BASELINE_ROLE)->first()?->permissions()->detach();

        $user = User::factory()->create(['role' => self::BASELINE_ROLE]);

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
