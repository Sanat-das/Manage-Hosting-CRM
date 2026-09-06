<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Installer\InstallerService;
use App\Support\AppSettings;
use App\Support\Branding;
use App\Support\GridFilters;
use App\Support\GridSort;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Laravel Dusk is excluded from package auto-discovery
        // (composer.json -> extra.laravel.dont-discover) and registered here
        // ONLY for the dedicated `dusk` environment that .env.dusk declares.
        //
        // Dusk's own provider guard is `environment() !== 'production'`, and it
        // publishes GET /_dusk/login/{userId} — an unauthenticated "log in as
        // any user id" route. That guard is not sufficient here: .env.example
        // ships APP_ENV=local and bootstrap/app.php copies it verbatim on first
        // boot, so a real deployment that never sets APP_ENV would have served
        // that route to anyone. Binding registration to `dusk` (never a
        // production value) closes it even when APP_ENV is left misconfigured.
        //
        // `php artisan dusk` swaps .env for .env.dusk (APP_ENV=dusk) before
        // booting, so the browser suite is unaffected.
        if ($this->shouldRegisterDusk() && class_exists(\Laravel\Dusk\DuskServiceProvider::class)) {
            $this->app->register(\Laravel\Dusk\DuskServiceProvider::class);
        }
    }

    /**
     * Whether Laravel Dusk's service provider may be registered this request.
     *
     * Never true for an HTTP request outside the `dusk` environment — that is
     * what keeps GET /_dusk/login/{userId} off a production host.
     */
    private function shouldRegisterDusk(): bool
    {
        if ($this->app->environment('dusk')) {
            return true;
        }

        // Chicken-and-egg: the `dusk` artisan command is itself provided by
        // DuskServiceProvider, and it is what swaps in .env.dusk (APP_ENV=dusk).
        // If the provider were bound to the dusk environment alone, the command
        // that produces that environment could never be invoked. Registering it
        // for its own CLI invocations keeps the browser suite working without
        // ever serving a route: console requests route nothing over HTTP, and
        // `php artisan serve` handles requests in a separate non-console
        // process, so this branch cannot expose the login endpoint.
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? '';

        return $command === 'dusk' || str_starts_with($command, 'dusk:');
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

        // ------------------------------------------------------------------
        // HostVexa branding — DB settings win over config/env at runtime.
        // Mirrors the timezone pattern above: try/catch so
        // pre-install boots (no settings table) keep the shipped defaults
        // and never throw. Delegates value resolution to App\Support\Branding
        // (fallback chain: DB → config → hardcoded).
        // ------------------------------------------------------------------
        try {
            $appName = Branding::appName();
            $tagline = Branding::tagline();

            if ($appName !== '') {
                config(['app.name' => $appName]);
                config(['adminlte.title' => $appName]);
                config(['adminlte.logo_img_alt' => $appName]);
            }

            // Tagline drives the title postfix — " | Hosting Management Platform".
            // Only override when DB has a non-empty tagline; clearing it keeps
            // whatever config/adminlte.php declares (or removes postfix entirely
            // if the admin explicitly wants no suffix).
            if ($tagline !== '' && $tagline !== Branding::DEFAULT_TAGLINE) {
                config(['adminlte.title_postfix' => ' | '.$tagline]);
            } elseif ($tagline !== '') {
                // Tagline is default but still ensure postfix matches it (covers
                // fresh installs where DB tagline equals default).
                $currentPostfix = (string) config('adminlte.title_postfix');
                if ($currentPostfix === '' || str_contains($currentPostfix, 'Hosting Management Platform')) {
                    config(['adminlte.title_postfix' => ' | '.$tagline]);
                }
            }

            // Logo / mark
            config(['adminlte.logo' => Branding::logoHtml()]);

            // logo_img should be a path relative to public/ for asset() in
            // sidebar.blade.php, but Branding resolves storage URLs for the
            // view share. For config backwards-compat, store the raw relative
            // path: sidebar.blade.php will still call asset().
            $logoPath = Branding::logoPath();
            if ($logoPath !== '') {
                // If it's a storage path, keep raw for config but views use branding mark_url.
                // If it's a public asset path, put it straight into config.
                if (! str_starts_with($logoPath, 'branding/') && ! str_starts_with($logoPath, 'storage/')) {
                    config(['adminlte.logo_img' => ltrim($logoPath, '/')]);
                }
                // Storage-backed logos: sidebar.blade.php reads config logo_img via asset(),
                // which would 404 for a storage path. Leave the default mark in config
                // and let the View share (`branding.mark_url`) be the authoritative URL
                // for any template that wants the uploaded logo. The sidebar override
                // is handled via the branding view data — see composer below.
            }

            // Footer — {year} already interpolated by Branding::footerText()
            config(['adminlte.footer_left' => Branding::footerText()]);

            // Sidebar theme — branding key wins over legacy general key.
            $brandingTheme = Branding::sidebarThemeResolved();
            if (in_array($brandingTheme, ['dark', 'light'], true)) {
                config(['adminlte.sidebar_theme' => $brandingTheme]);
            }

            // Mail from name — when the admin hasn't set a dedicated From name,
            // use the branding app name so transactional emails carry HostVexa
            // (or the custom name) instead of the .env default.
            $mailFromName = trim((string) config('mail.from.name'));
            $envAppName = (string) env('APP_NAME', '');
            $legacyMailName = trim((string) AppSettings::get('mail_from_name'));
            if ($legacyMailName === '' && ($mailFromName === '' || $mailFromName === 'Laravel' || $mailFromName === $envAppName)) {
                config(['mail.from.name' => $appName]);
            }
        } catch (\Throwable) {
            // Branding table/group unreadable — keep shipped defaults.
        }

        // Share branding to every Blade view — `$branding` is available in all
        // blades (master, auth, client portal, emails, PDFs) without each
        // controller having to pass it. Also re-resolve per-request via a
        // composer so queued/PDF renders that boot without HTTP still see it;
        // the closure re-reads Branding::all() rather than capturing stale config.
        try {
            View::share('branding', Branding::all());

            View::composer('*', function ($view) {
                // Re-resolve fresh so late mutations (e.g. tests that mock
                // settings mid-request) are reflected. Cheap — AppSettings is
                // request-cached and Branding does no I/O beyond that.
                try {
                    $view->with('branding', Branding::all());
                } catch (\Throwable) {
                    // Never break view rendering if branding is unreadable.
                }
            });
        } catch (\Throwable) {
            // View system not booted (e.g. pure console without views) — safe to ignore.
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
