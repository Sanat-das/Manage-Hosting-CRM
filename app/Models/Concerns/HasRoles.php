<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Adds role and permission helpers to the User model.
 */
trait HasRoles
{
    /**
     * Role and permission names resolved for this instance and memoized.
     *
     * The sidebar's gate asks for one ability per menu item, so a single
     * render walks ~100 `can` keys against one User instance -- each one an
     * ability check, an implied-ability check, or a role lookup -- and every
     * one of them used to hit the role pivot on its own.
     *
     * @var array{roles: list<string>, permissions: array<string, true>}|null
     */
    protected ?array $resolvedAccess = null;

    /**
     * Roles assigned to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'adminlte_role_user');
    }

    /**
     * Determine whether the user has any of the given role(s).
     *
     * The reference schema stores the primary access level in the `users.role`
     * column; AdminLTE RBAC links are the secondary (granular) source. Either
     * matching source counts.
     *
     * @param  string|array<int, string>  $role
     */
    public function hasRole(string|array $role): bool
    {
        $roles = (array) $role;

        if (in_array($this->role, $roles, true)) {
            return true;
        }

        return array_intersect($this->resolvedAccess()['roles'], $roles) !== [];
    }

    /**
     * Determine whether any of the user's roles grants the given permission.
     *
     * Checks the pivot-assigned roles first, then falls back to the string
     * `users.role` column so legacy / string-only users inherit the
     * equivalent Role's permissions (e.g. editor/viewer panel access).
     *
     * Admins are not special-cased. They used to return true here before any
     * row was read, which made the admin role's permissions unenforceable --
     * stripping every one of them changed nothing -- and hid the installer
     * seeding admin with 97 of 103 permissions for as long as it did. The
     * admin role holds every permission instead, seeded by AdminLteRbacSeeder
     * and backfilled for existing installs by
     * 2026_09_11_000001_grant_admin_role_every_permission.
     */
    public function hasPermission(string $permission): bool
    {
        return isset($this->resolvedAccess()['permissions'][$permission]);
    }

    /**
     * Determine whether the user holds any permission at all.
     *
     * Resolved the same way as hasPermission(): pivot roles first, then the
     * Role named by the `users.role` column. Used by AdminMiddleware to keep a
     * role that has been stripped of every permission from reaching the panel
     * shell, rather than relying on each individual route to turn it away.
     */
    public function hasAnyPermission(): bool
    {
        return $this->resolvedAccess()['permissions'] !== [];
    }

    /**
     * The role names and permission names this user holds, resolved once.
     *
     * The sources are exactly the ones the trait has always resolved: the
     * pivot-assigned roles and their permissions, plus the permissions of
     * the Role named by the legacy `users.role` column when such a role
     * exists. An instance whose `roles` relation has been (re)loaded is read
     * from that relation instead, so a reload can never answer from a memo
     * built before it; assignRole() drops the memo as well, since it mutates
     * the pivot directly.
     *
     * @return array{roles: list<string>, permissions: array<string, true>}
     */
    protected function resolvedAccess(): array
    {
        if ($this->resolvedAccess !== null && ! $this->relationLoaded('roles')) {
            return $this->resolvedAccess;
        }

        $roles = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->with('permissions')->get();

        $roleNames = [];
        $permissions = [];

        foreach ($roles as $role) {
            $roleNames[] = $role->name;

            foreach ($role->permissions as $permission) {
                $permissions[$permission->name] = true;
            }
        }

        if (! empty($this->role)) {
            $roleModel = Role::where('name', $this->role)->first();

            if ($roleModel !== null) {
                foreach ($roleModel->permissions()->pluck('name') as $name) {
                    $permissions[$name] = true;
                }
            }
        }

        return $this->resolvedAccess = ['roles' => $roleNames, 'permissions' => $permissions];
    }

    /**
     * Assign a role to the user by name without detaching existing roles.
     */
    public function assignRole(string $role): void
    {
        $model = Role::where('name', $role)->first();

        if ($model !== null) {
            $this->roles()->syncWithoutDetaching($model);

            $this->resolvedAccess = null;
        }
    }

    /**
     * Determine whether the user has the "admin" role.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
