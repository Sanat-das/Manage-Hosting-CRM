<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\AppSettings;

/**
 * Block self-registration when the admin has disabled it.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AppSettings::bool('registration_enabled', true), 404);

        return $next($request);
    }
}
