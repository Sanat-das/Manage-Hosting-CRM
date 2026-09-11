<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;

/**
 * Builders for panel users whose permissions are real.
 *
 * `TestCase::seedPanelRbac()` seeds the RBAC tables before every test, so the
 * `users.role` column now resolves to the same permissions it does in
 * production. That matters because `hasPermission()` falls back to the Role
 * named by that column: picking a role name off the top of your head decides
 * the user's permissions, whether or not you meant it to.
 *
 * Four tests used `['role' => 'support']` to mean "a panel user without the
 * permission under test" and passed only because the RBAC tables were empty —
 * support really does hold `hosting.view`, `tickets.view` and
 * `tickets.transfer`, so the 403 they asserted was one production would never
 * return. `panelUserWithoutPermission()` asserts the precondition instead of
 * assuming it, so that can't recur silently.
 */
trait CreatesPanelUsers
{
    /**
     * A panel user holding every permission.
     */
    protected function adminUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'admin'], $attributes));

        $adminRole = Role::where('name', 'admin')->first();

        if ($adminRole !== null) {
            $user->roles()->syncWithoutDetaching($adminRole->id);
        }

        return $user;
    }

    /**
     * A panel user holding exactly the given permissions and nothing else.
     *
     * The `users.role` column is set to a role that grants nothing on its own,
     * so the permissions under test come only from the bespoke role attached
     * here and the test controls them completely.
     */
    protected function panelUserWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create(['role' => self::BARE_PANEL_ROLE]);

        $role = Role::firstOrCreate(
            ['name' => 'test-scoped-role'],
            ['label' => 'Test Scoped Role'],
        );

        $role->permissions()->syncWithoutDetaching(
            \App\Models\Permission::whereIn('name', $permissions)->pluck('id')
        );

        $user->roles()->syncWithoutDetaching($role->id);

        return $user->fresh();
    }

    /**
     * A panel user proven not to hold the given permissions.
     *
     * Asserts rather than assumes: if the baseline role ever gains one of
     * them, this fails loudly here instead of quietly turning a 403 assertion
     * somewhere else into a test that proves nothing.
     */
    protected function panelUserWithoutPermission(string ...$permissions): User
    {
        $user = User::factory()->create(['role' => self::BARE_PANEL_ROLE]);

        foreach ($permissions as $permission) {
            $this->assertFalse(
                $user->hasPermission($permission),
                sprintf(
                    'panelUserWithoutPermission() was asked for a user lacking [%s], but the '
                        . '"%s" role now grants it — pick a different baseline role or this test '
                        . 'is asserting a 403 that cannot happen.',
                    $permission,
                    self::BARE_PANEL_ROLE,
                ),
            );
        }

        return $user;
    }

    /**
     * The `users.role` value used as a baseline for "holds nothing relevant".
     *
     * Must be a value `StaffUserRequest` accepts. `marketing` is the only panel
     * role with no hosting.*, tickets.* or settings.* permissions at all;
     * support, sales, staff and viewer all carry `hosting.view`.
     */
    private const BARE_PANEL_ROLE = 'marketing';
}
