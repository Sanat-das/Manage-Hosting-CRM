<?php

namespace App\Http\Middleware;

use App\Services\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the installer wizard out of an already-installed application.
 *
 * Once install.lock exists the installer must no longer be reachable,
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
        // isInstalled(), not lockExists(): install.lock is gitignored, so a
        // deploy that loses it would otherwise reopen this unauthenticated
        // wizard on a live application with real data in it.
        if (InstallerService::isInstalled()) {
            return redirect('/');
        }

        return $next($request);
    }
}
