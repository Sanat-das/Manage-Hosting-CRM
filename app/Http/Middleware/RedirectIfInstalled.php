<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the installer wizard out of an already-installed application.
 *
 * Once APP_INSTALLED is true the installer must no longer be reachable,
 * otherwise the setup form could be re-submitted and wipe configuration.
 *
 * Usage: ->middleware('redirect.if.installed') on the installer route group.
 */
class RedirectIfInstalled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.installed')) {
            return redirect('/');
        }

        return $next($request);
    }
}
