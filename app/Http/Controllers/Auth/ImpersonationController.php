<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin → client session impersonation.
 *
 * The original (impersonating) user id is stored in the session so the
 * impersonation can be reverted at any time. The AdminMiddleware allows
 * impersonated sessions through even though the impersonated user's role
 * is not admin.
 */
class ImpersonationController extends Controller
{
    /**
     * Start impersonating the given user.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        $impersonator = $request->user();

        abort_unless($impersonator !== null && $impersonator->isAdmin(), 403);
        abort_if($user->isAdmin(), 403, 'Administrators cannot be impersonated.');
        abort_if($user->id === $impersonator->id, 403, 'You cannot impersonate yourself.');

        $request->session()->put('impersonator_id', $impersonator->id);

        Auth::login($user);

        ActivityLog::create([
            'action' => 'impersonation_started',
            'description' => "Admin {$impersonator->full_name} ({$impersonator->email}) started impersonating {$user->full_name} ({$user->email})",
            'ip_address' => $request->ip(),
            'metadata' => [
                'impersonator_id' => $impersonator->id,
                'impersonated_id' => $user->id,
            ],
        ]);

        // Land in the client portal: impersonation targets are always clients
        // (the Login As button lives on customer pages), and the admin
        // dashboard would 403 them — its `permission:dashboard.view` gate
        // ignores impersonated sessions, so the client has no permission.
        return redirect()->route('client.dashboard')
            ->with('success', "You are now viewing the portal as {$user->full_name}.");
    }

    /**
     * Stop impersonating and return to the original user.
     */
    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');

        if ($impersonatorId !== null) {
            $impersonator = User::find($impersonatorId);

            if ($impersonator !== null) {
                $impersonatedUser = $request->user();

                Auth::login($impersonator);

                ActivityLog::create([
                    'action' => 'impersonation_stopped',
                    'description' => "Admin {$impersonator->full_name} ({$impersonator->email}) stopped impersonating, returned to their own session",
                    'ip_address' => $request->ip(),
                    'metadata' => [
                        'impersonator_id' => $impersonator->id,
                        'impersonated_id' => $impersonatedUser?->id,
                    ],
                ]);

                // Customers module (admin.customers.index) lands in Session 2.4;
                // fall back to the dashboard until then.
                $redirect = \Illuminate\Support\Facades\Route::has('admin.customers.index')
                    ? redirect()->route('admin.customers.index')
                    : redirect()->route('admin.dashboard');

                return $redirect
                    ->with('success', 'Impersonation ended. You are back as '.$impersonator->full_name.'.');
            }
        }

        return redirect()->route('admin.dashboard');
    }
}
