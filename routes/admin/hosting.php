<?php

use App\Http\Controllers\Admin\HostingController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerGroupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosting module — admin web routes
|--------------------------------------------------------------------------
|
| Permission gates:
|   hosting.view         — read list / show / available-ips
|   hosting.create       — create / store new services
|   hosting.edit         — update, change-package, change-password,
|                          update-billing, notes, IP assign/release
|   hosting.suspend      — suspend / unsuspend
|   hosting.delete       — destroy
|   hosting.manage       — servers CRUD (coarse manage for server admin)
|   hosting.server_groups — server groups CRUD
|
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // --- Hosting accounts ---
    Route::get('hosting', [HostingController::class, 'index'])
        ->middleware('permission:hosting.view')
        ->name('hosting.index');
    Route::get('hosting/create', [HostingController::class, 'create'])
        ->middleware('permission:hosting.create')
        ->name('hosting.create');
    Route::post('hosting', [HostingController::class, 'store'])
        ->middleware('permission:hosting.create')
        ->name('hosting.store');
    Route::get('hosting/{hostingAccount}', [HostingController::class, 'show'])
        ->middleware('permission:hosting.view')
        ->name('hosting.show');
    Route::get('hosting/{hostingAccount}/edit', [HostingController::class, 'edit'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.edit');
    Route::get('hosting/{hostingAccount}/available-ips', [HostingController::class, 'searchAvailableIps'])
        ->middleware('permission:hosting.view')
        ->name('hosting.available-ips');
    Route::put('hosting/{hostingAccount}', [HostingController::class, 'update'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.update');
    Route::delete('hosting/{hostingAccount}', [HostingController::class, 'destroy'])
        ->middleware('permission:hosting.delete')
        ->name('hosting.destroy');

    // Lifecycle actions
    Route::post('hosting/{hostingAccount}/suspend', [HostingController::class, 'suspend'])
        ->middleware('permission:hosting.suspend')
        ->name('hosting.suspend');
    Route::post('hosting/{hostingAccount}/unsuspend', [HostingController::class, 'unsuspend'])
        ->middleware('permission:hosting.suspend')
        ->name('hosting.unsuspend');
    Route::post('hosting/{hostingAccount}/change-package', [HostingController::class, 'changePackage'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.change-package');
    Route::post('hosting/{hostingAccount}/change-password', [HostingController::class, 'changePassword'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.change-password');

    // Billing
    Route::put('hosting/{hostingAccount}/billing', [HostingController::class, 'updateBilling'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.update-billing');

    // Notes
    Route::post('hosting/{hostingAccount}/notes', [HostingController::class, 'storeNote'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.notes.store');
    Route::put('hosting/{hostingAccount}/notes/{note}', [HostingController::class, 'updateNote'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.notes.update');
    Route::delete('hosting/{hostingAccount}/notes/{note}', [HostingController::class, 'destroyNote'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.notes.destroy');

    // IP lease actions
    Route::post('hosting/{hostingAccount}/pull-ip', [HostingController::class, 'pullIp'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.pull-ip');
    Route::post('hosting/{hostingAccount}/assign-ips', [HostingController::class, 'assignIps'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.assign-ips');
    Route::post('hosting/{hostingAccount}/release-ip', [HostingController::class, 'releaseIp'])
        ->middleware('permission:hosting.edit')
        ->name('hosting.release-ip');

    // --- Servers (coarse hosting.manage / hosting.view) ---
    Route::get('servers', [ServerController::class, 'index'])
        ->middleware('permission:hosting.view')
        ->name('servers.index');
    Route::get('servers/create', [ServerController::class, 'create'])
        ->middleware('permission:hosting.manage')
        ->name('servers.create');
    Route::post('servers', [ServerController::class, 'store'])
        ->middleware('permission:hosting.manage')
        ->name('servers.store');
    Route::get('servers/{server}', [ServerController::class, 'show'])
        ->middleware('permission:hosting.view')
        ->name('servers.show');
    Route::get('servers/{server}/edit', [ServerController::class, 'edit'])
        ->middleware('permission:hosting.manage')
        ->name('servers.edit');
    Route::put('servers/{server}', [ServerController::class, 'update'])
        ->middleware('permission:hosting.manage')
        ->name('servers.update');

    // --- Server groups ---
    Route::get('server-groups', [ServerGroupController::class, 'index'])
        ->middleware('permission:hosting.server_groups')
        ->name('server-groups.index');
    Route::get('server-groups/create', [ServerGroupController::class, 'create'])
        ->middleware('permission:hosting.server_groups')
        ->name('server-groups.create');
    Route::post('server-groups', [ServerGroupController::class, 'store'])
        ->middleware('permission:hosting.server_groups')
        ->name('server-groups.store');
    Route::get('server-groups/{serverGroup}/edit', [ServerGroupController::class, 'edit'])
        ->middleware('permission:hosting.server_groups')
        ->name('server-groups.edit');
    Route::put('server-groups/{serverGroup}', [ServerGroupController::class, 'update'])
        ->middleware('permission:hosting.server_groups')
        ->name('server-groups.update');
});
