<?php

use App\Http\Controllers\Admin\SslController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin SSL certificates (web)
|--------------------------------------------------------------------------
|
| Self-contained route group for the SSL certificate module. Loaded via
| bootstrap/app.php `withRouting(..., then:)` so the routes resolve before
| the wiring task runs. The wiring task must NOT re-require this file
| (duplicate route names would crash the app) — it only needs to add the
| sidebar entry in config/adminlte.php.
|
| Permission gates:
|   - view (index/show): hosting.view
|   - manage (create/store/edit/update/destroy): settings.edit
| A dedicated `ssl.*` permission set does not exist yet (see learnings).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])
    ->prefix('admin/ssl')
    ->name('admin.ssl.')
    ->group(function () {
        // NB: `ssl/create` must be registered before `ssl/{ssl}`.
        Route::get('/', [SslController::class, 'index'])
            ->middleware('permission:hosting.view')
            ->name('index');
        Route::get('create', [SslController::class, 'create'])
            ->middleware('permission:settings.edit')
            ->name('create');
        Route::post('/', [SslController::class, 'store'])
            ->middleware('permission:settings.edit')
            ->name('store');
        Route::get('{ssl}', [SslController::class, 'show'])
            ->middleware('permission:hosting.view')
            ->name('show');
        Route::get('{ssl}/edit', [SslController::class, 'edit'])
            ->middleware('permission:settings.edit')
            ->name('edit');
        Route::put('{ssl}', [SslController::class, 'update'])
            ->middleware('permission:settings.edit')
            ->name('update');
        Route::delete('{ssl}', [SslController::class, 'destroy'])
            ->middleware('permission:settings.edit')
            ->name('destroy');
    });
