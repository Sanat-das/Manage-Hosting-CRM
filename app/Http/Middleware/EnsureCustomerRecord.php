<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep client-area requests coherent when the authenticated client has no
 * linked Customer record yet (e.g. a freshly self-registered account).
 *
 * The dashboard is the landing page for that state — it renders a friendly
 * "account pending setup" screen instead of a bare 404 (see
 * Client\DashboardController). Every other client route redirects there so
 * the pending client never falls into a dead-end module page. Profile routes
 * are exempt: a pending client may still correct their own details.
 */
class EnsureCustomerRecord
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->customer === null
            && ! $request->routeIs('client.dashboard', 'client.profile', 'client.profile.update')) {
            return redirect()->route('client.dashboard');
        }

        return $next($request);
    }
}
