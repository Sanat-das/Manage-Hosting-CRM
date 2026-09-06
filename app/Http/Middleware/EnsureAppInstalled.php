<?php

namespace App\Http\Middleware;

use App\Services\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard the whole web application behind the first-run installer.
 *
 * While the application is not installed yet (no install.lock file in the
 * project root), every web request is redirected to the installer wizard.
 * The installer's own routes (install/*) are excluded so the wizard itself
 * stays reachable. Deleting install.lock puts the app back into this state,
 * exactly like WordPress — a fresh reinstall is just a file deletion away.
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
        // Must use the same predicate as RedirectIfInstalled. If this checked
        // only the lock file while that one also checks the database, a site
        // whose install.lock went missing would bounce forever: here to
        // /install, and there straight back to /.
        if (! InstallerService::isInstalled() && ! $request->is('install*')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
