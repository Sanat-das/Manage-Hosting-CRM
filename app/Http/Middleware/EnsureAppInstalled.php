<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard the whole web application behind the first-run installer.
 *
 * While the application is not installed yet (APP_INSTALLED is not true),
 * every web request is redirected to the installer wizard. The installer's
 * own routes (install/*) are excluded so the wizard itself stays reachable.
 *
 * Usage: appended to the "web" middleware group in bootstrap/app.php.
 */
class EnsureAppInstalled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.installed') && ! $request->is('install*')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
