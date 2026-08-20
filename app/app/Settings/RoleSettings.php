<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Role / RBAC defaults (new T4.2 group: role).
 */
class RoleSettings extends Settings
{
    public string $role_default_role = 'client';

    public bool $role_allow_assignment = true;

    public bool $role_show_permissions = true;

    public string $role_guard = 'web';

    public bool $role_protect_system_roles = true;

    public static function group(): string
    {
        return 'role';
    }

    public static function rules(): array
    {
        return [
            'role_default_role' => ['nullable', 'string', 'max:50'],
            'role_allow_assignment' => ['nullable', 'in:1,0,yes,no,true,false'],
            'role_show_permissions' => ['nullable', 'in:1,0,yes,no,true,false'],
            'role_guard' => ['nullable', 'string', 'max:50'],
            'role_protect_system_roles' => ['nullable', 'in:1,0,yes,no,true,false'],
        ];
    }
}
