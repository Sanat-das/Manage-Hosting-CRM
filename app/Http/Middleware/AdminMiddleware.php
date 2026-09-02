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

        $allowed = $roles === [] ? ['admin', 'support', 'sales', 'marketing', 'editor', 'viewer'] : $roles;

        // Impersonated sessions: the original admin is stored in the session,
        // so allow the impersonated user through even though their role is client.
        if ($user->hasRole($allowed) || $request->session()->has('impersonator_id')) {
            return $next($request);
        }

        abort(403, 'You do not have permission to access the admin panel.');
    }
}
