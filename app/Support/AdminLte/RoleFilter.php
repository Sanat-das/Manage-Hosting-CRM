<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Shows or hides menu items based on the authenticated user's `role` column.
 *
 * Menu items may carry a `role` key (string or array).  When present the
 * item is only rendered for users whose `role` attribute matches one of the
 * listed values.  Items without a `role` key pass through unchanged.
 *
 * This bypasses the Gate system — necessary because the AdminLTE package's
 * own Gate::before short-circuits for admin users (returns true for every
 * ability), making `can`-based filtering unreliable for client-only items.
 */
class RoleFilter implements FilterInterface
{
    public function transform(array $item): ?array
    {
        if (! isset($item['role'])) {
            return $item;
        }

        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $allowedRoles = (array) $item['role'];

        return in_array($user->role, $allowedRoles, true) ? $item : null;
    }
}
