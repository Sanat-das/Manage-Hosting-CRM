<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route group to client-portal users.
 *
 * Usage: ->middleware('client')
 */
class ClientMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('client.login');
        }

        abort_unless($user->hasRole('client'), 403, 'You do not have permission to access the client portal.');

        return $next($request);
    }
}
