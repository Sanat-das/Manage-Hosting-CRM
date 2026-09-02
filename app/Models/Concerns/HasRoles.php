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

        return $this->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * Determine whether any of the user's roles grants the given permission.
     *
     * Checks the pivot-assigned roles first, then falls back to the string
     * `users.role` column so legacy / string-only users inherit the
     * equivalent Role's permissions (e.g. editor/viewer panel access).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists()) {
            return true;
        }

        if (! empty($this->role)) {
            $roleModel = Role::where('name', $this->role)->first();

            if ($roleModel !== null && $roleModel->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assign a role to the user by name without detaching existing roles.
     */
    public function assignRole(string $role): void
    {
        $model = Role::where('name', $role)->first();

        if ($model !== null) {
            $this->roles()->syncWithoutDetaching($model);
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
