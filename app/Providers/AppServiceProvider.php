<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Installer\InstallerService;
use App\Support\AppSettings;
use App\Support\GridFilters;
use App\Support\GridSort;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
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
        Paginator::useBootstrapFive();

        GridFilters::register();
        GridSort::register();

        // While the application is not installed (no install.lock), the setup
        // wizard must be able to render before the database even exists.
        // DB-backed session/cache would throw on the wizard page (no tables
        // yet), so force the file drivers until the lock file appears.
        if (! InstallerService::lockExists()) {
            config(['session.driver' => 'file', 'cache.default' => 'file']);
        }

        // Runtime timezone — the saved Settings > General > Timezone value wins
        // over the config default (env APP_TIMEZONE). Laravel stamps PHP's default
        // timezone once during boot from config('app.timezone'), which cannot see
        // DB-backed settings yet, so re-apply here for every request. Both config
        // and date_default_timezone_set are updated: Carbon/Blade helpers read the
        // former, plain PHP date() reads the latter.
        // Defensive try/catch: the settings table does not exist before install or
        // mid-migration; an unreadable value simply keeps the config default.
        try {
            $savedTimezone = AppSettings::get('timezone');
            if (is_string($savedTimezone) && $savedTimezone !== ''
                && in_array($savedTimezone, \DateTimeZone::listIdentifiers(), true)) {
                config(['app.timezone' => $savedTimezone]);
                date_default_timezone_set($savedTimezone);
            }
        } catch (\Throwable) {
            // Not installed / no settings table yet — keep env/config default.
        }

        // Bridge the RBAC permission vocabulary (adminlte_roles / adminlte_roles
        // permissions, see App\Models\Concerns\HasRoles) into Laravel's Gate so
        // the `@can('permission.name')` directives used by the admin views work
        // against the same permission table the route middleware uses.
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->hasPermission($ability)) {
                return true;
            }

            // Fallback: `*.manage` implies `*.view`
            if (str_ends_with($ability, '.view')) {
                $manage = substr($ability, 0, -5) . '.manage';
                if ($user->hasPermission($manage)) {
                    return true;
                }
            }

            return null;
        });

        // Role-aware sidebar menus (see config/adminlte.php menu items).
        // Client items use a custom `RoleFilter` (bypasses the Gate system
        // because the AdminLTE package's Gate::before short-circuits for
        // admin users). Admin section headers still use `can: 'admin.panel'`
        // which works correctly — the Gate::before bridge returns null for
        // non-permission abilities, falling through to this definition.
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

        // Password reset email — 3 per 10 minutes per actor+target URL to prevent
        // spamming reset links at a specific user account.
        RateLimiter::for('password-reset-email', function (Request $request) {
            $key = ($request->user()?->getAuthIdentifier() ?: $request->ip()).'|'.$request->path();

            return Limit::perMinutes(10, 3)->by($key)->response(function () {
                return back()->withErrors(['error' => 'Too many password reset requests. Please wait before trying again.']);
            });
        });

        // Password direct-set — 5 per minute per actor+target URL to blunt
        // any attempt to cycle passwords through the admin form.
        RateLimiter::for('password-set', function (Request $request) {
            $key = ($request->user()?->getAuthIdentifier() ?: $request->ip()).'|'.$request->path();

            return Limit::perMinute(5)->by($key)->response(function () {
                return back()->withErrors(['error' => 'Too many password set attempts. Please wait before trying again.']);
            });
        });
    }
}
