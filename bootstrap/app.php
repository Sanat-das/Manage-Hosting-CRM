<?php

// Must run before Laravel boots: without APP_KEY the app throws before any route is reached.
(static function () {
    $base    = dirname(__DIR__);
    $envPath = $base.'/.env';
    $example = $base.'/.env.example';

    if (! file_exists($envPath) && file_exists($example)) {
        copy($example, $envPath);
    }

    if (file_exists($envPath)) {
        $content = file_get_contents($envPath);
        if (preg_match('/^APP_KEY=\s*$/m', $content)) {
            $key     = 'base64:'.base64_encode(random_bytes(32));
            $content = preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY='.$key, $content);
            file_put_contents($envPath, $content);
        }
    }
})();

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ClientMiddleware;
use App\Http\Middleware\EnsureAppInstalled;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\EnsureCustomerRecord;
use App\Http\Middleware\EnsureRegistrationEnabled;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // First-run installer routes (see routes/install.php).
            require base_path('routes/install.php');

            // Additional self-contained module route files.
            //
            // Each file is self-contained with its own prefix, middleware,
            // and route name prefix. They must NOT be required in web.php
            // or api.php (duplicate route names would crash the app).
            require base_path('routes/admin/ssl.php');
            require base_path('routes/admin/products.php');
            require base_path('routes/admin/orders.php');
            require base_path('routes/admin/hosting.php');
            require base_path('routes/admin/users.php');
            require base_path('routes/admin/support.php');
            require base_path('routes/admin/billing.php');
            require base_path('routes/admin/domains.php');
            require base_path('routes/admin/settings.php');
            require base_path('routes/admin/email.php');
            require base_path('routes/admin/config.php');
            require base_path('routes/admin/enterprise.php');
            require base_path('routes/admin/provisioning.php');
            require base_path('routes/admin/cart.php');
            require base_path('routes/admin/search.php');
            require base_path('routes/admin/dns.php');
            require base_path('routes/admin/inventory.php');
            require base_path('routes/admin/notifications.php');
            require base_path('routes/admin/tax-rates.php');
            require base_path('routes/admin/product-bundles.php');
            require base_path('routes/admin/product-upgrades.php');
            require base_path('routes/admin/modules.php');
            require base_path('routes/admin/cron.php');
            require base_path('routes/admin/system.php');

            require base_path('routes/client.php');

            // Customer chat endpoints reachable without an account; they
            // authorise from the conversation's guest token.
            require base_path('routes/chat.php');

            // Inbound third-party callbacks (payment gateways). Registered
            // here rather than in web.php so they carry no session/CSRF
            // middleware — see routes/webhooks.php.
            require base_path('routes/webhooks.php');

            require base_path('routes/api/ssl.php');
            require base_path('routes/api/products.php');
            require base_path('routes/api/orders.php');
            require base_path('routes/api/hosting.php');
            require base_path('routes/api/users.php');
            require base_path('routes/api/support.php');
        },
    )
    // Websocket channel authorisation for the chat (routes/channels.php). This
    // also registers POST /broadcasting/auth, which Echo calls before it will
    // join any private or presence channel.
    //
    // `auth` is added to the framework's default `web`: without it an
    // unauthenticated POST reaches the broadcaster, which correctly denies it,
    // but the 403 page then renders the AdminLTE user menu and fatals on a null
    // user. A guest belongs at the login screen, which is what `auth` does.
    // Guest customer chat does not use this endpoint — it authorises with its
    // own token on its own client route.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'admin' => AdminMiddleware::class,
            'client' => ClientMiddleware::class,
            'customer.record' => EnsureCustomerRecord::class,
            'app.installed' => EnsureAppInstalled::class,
            'redirect.if.installed' => RedirectIfInstalled::class,
            'registration.enabled' => EnsureRegistrationEnabled::class,
            'security.headers' => SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            return $request->is('admin') || $request->is('admin/*')
                ? route('admin.login')
                : route('client.login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();

            return $user->hasRole('client')
                ? route('client.dashboard')
                : route('admin.dashboard');
        });

        // Apply the `api` rate limiter (defined in AppServiceProvider) to every
        // route in the `api` middleware group, including the self-contained
        // module files (routes/api/*.php) that opt into that group.
        $middleware->throttleApi();

        // The About & Updates page polls this endpoint every 2 s while an update
        // runs, and the update itself enables maintenance mode at step 2 of 7.
        // Without this exclusion every poll for the rest of the run answered 503
        // and the browser silently swallowed it, so the progress bar froze at
        // 30% until `artisan up` — with no way to bypass, since the update
        // generates a random `--secret` and discards it.
        //
        // Laravel feeds this same list into the `down` file, so the
        // pre-framework shim in public/index.php honours it too.
        $middleware->preventRequestsDuringMaintenance(except: [
            'admin/system/update/progress',
        ]);

        // While the application is not installed, every web request is
        // funnelled to the first-run installer wizard.
        $middleware->web(append: [
            EnsureAppInstalled::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
