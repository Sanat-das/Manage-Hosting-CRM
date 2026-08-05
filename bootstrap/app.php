<?php

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

            require base_path('routes/client.php');

            require base_path('routes/api/ssl.php');
            require base_path('routes/api/products.php');
            require base_path('routes/api/orders.php');
            require base_path('routes/api/hosting.php');
            require base_path('routes/api/users.php');
            require base_path('routes/api/support.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'client' => \App\Http\Middleware\ClientMiddleware::class,
            'customer.record' => \App\Http\Middleware\EnsureCustomerRecord::class,
            'app.installed' => \App\Http\Middleware\EnsureAppInstalled::class,
            'redirect.if.installed' => \App\Http\Middleware\RedirectIfInstalled::class,
            'registration.enabled' => \App\Http\Middleware\EnsureRegistrationEnabled::class,
        ]);

        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            return $request->is('admin') || $request->is('admin/*')
                ? route('admin.login')
                : route('client.login');
        });

        $middleware->redirectUsersTo(function (\Illuminate\Http\Request $request) {
            $user = $request->user();

            return $user->hasRole('client')
                ? route('client.dashboard')
                : route('admin.dashboard');
        });

        // Apply the `api` rate limiter (defined in AppServiceProvider) to every
        // route in the `api` middleware group, including the self-contained
        // module files (routes/api/*.php) that opt into that group.
        $middleware->throttleApi();

        // While the application is not installed, every web request is
        // funnelled to the first-run installer wizard.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureAppInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
