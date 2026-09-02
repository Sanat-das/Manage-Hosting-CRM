<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Sanctum `/api/tickets*` routes with `tickets.view` for
 * staff/admin tokens. Client tokens are exempt — they are not part of the
 * RBAC role system and are instead scoped to their own customer_id inside
 * `Api\TicketController`.
 */
class GateTicketApiVisibility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && ($user->role === 'client' || $user->hasPermission('tickets.view')), 403);

        return $next($request);
    }
}
