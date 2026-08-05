<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bridge the RBAC permission vocabulary (adminlte_roles / adminlte_roles
        // permissions, see App\Models\Concerns\HasRoles) into Laravel's Gate so
        // the `@can('permission.name')` directives used by the admin views work
        // against the same permission table the route middleware uses.
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            return $user->hasPermission($ability) ? true : null;
        });

        // Role-aware sidebar menus (see config/adminlte.php menu items).
        // `client.portal` shows the client-portal navigation to clients;
        // `admin.panel` keeps the admin section headers/items visible only to
        // staff. The Gate::before bridge above returns null for these names
        // (no matching permission), so the definitions below decide.
        Gate::define('client.portal', fn (User $user) => $user->hasRole('client'));
        Gate::define('admin.panel', fn (User $user) => ! $user->hasRole('client'));

        // Default API limiter — applied to the whole `api` middleware group via
        // `$middleware->throttleApi()` in bootstrap/app.php. Keyed per user when
        // authenticated, per IP otherwise. Generous ceiling: it exists to stop
        // runaway abuse, not to constrain legitimate API consumers.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // First-run installer — brute-forcing the wizard's DB/admin credentials
        // is a classic takeover vector, so keep this tight and per-IP.
        RateLimiter::for('install', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Public self-registration — throttled per IP to slow spam/credential
        // stuffing while leaving genuine sign-ups unaffected.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Client invoice payment — throttled per user to stop accidental or
        // malicious duplicate payment submissions.
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // Admin impersonation — per-user cap on session switches.
        RateLimiter::for('impersonate', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // Admin panel writes — applied to the `permission:`-gated admin resource
        // routes via the `throttle:admin` middleware on every admin group header.
        // READ/WRITE-AWARE: read-only browsing (GET/HEAD/OPTIONS) gets a generous
        // ceiling so list pagination is never throttled, while state-changing
        // requests (POST/PUT/PATCH/DELETE) are capped tightly per user to blunt
        // brute-force / abusive submissions on permission-gated endpoints.
        RateLimiter::for('admin', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return $request->isMethod('GET')
                ? Limit::perMinute(300)->by($key)
                : Limit::perMinute(30)->by($key);
        });
    }
}
