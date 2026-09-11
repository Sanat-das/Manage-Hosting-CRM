<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route group to panel (admin/staff) users.
 *
 * Staff members are granted panel access through the AdminLTE roles
 * defined by the RBAC seeder (admin, support, sales, marketing).
 *
 * Usage: ->middleware('admin')
 */
class AdminMiddleware
{
    /**
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('admin.login');
        }

        // `staff` is offered by StaffUserRequest and seeded with a real (read-only)
        // permission set, so it belongs here: without it, every staff account the
        // Users form creates is refused the panel outright.
        $allowed = $roles === [] ? ['admin', 'staff', 'support', 'sales', 'marketing', 'editor', 'viewer'] : $roles;

        // Impersonated sessions: the original admin is stored in the session,
        // so allow the impersonated user through even though their role is client.
        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        abort_unless($user->hasRole($allowed), 403, 'You do not have permission to access the admin panel.');

        // Defence in depth. Every admin route carries its own `permission:` gate
        // today, so a role stripped of all its permissions in the Roles screen is
        // already turned away page by page -- but that is a property of each
        // route, and one new route added without a gate would silently become
        // reachable by anyone holding a panel role name. Deny at the door too.
        abort_unless(
            $user->hasAnyPermission(),
            403,
            'Your role no longer grants any permissions. Ask an administrator to restore them.',
        );

        return $next($request);
    }
}
