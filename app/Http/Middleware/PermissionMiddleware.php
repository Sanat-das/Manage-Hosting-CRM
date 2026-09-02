<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Ensure the authenticated user has the given permission.
     *
     * Usage: ->middleware('permission:manage-projects')
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasPermission($permission)) {
            return $next($request);
        }

        // Fallback: `*.manage` implies `*.view` — e.g. notifications.manage can view inbox.
        if ($user !== null && str_ends_with($permission, '.view')) {
            $managePermission = substr($permission, 0, -5) . '.manage';

            if ($user->hasPermission($managePermission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
