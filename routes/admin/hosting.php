<?php

use App\Http\Controllers\Admin\HostingController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerGroupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosting module — admin web routes (Session 3A.2)
|--------------------------------------------------------------------------
|
| SELF-CONTAINED route file. Wire it into routes/web.php by requiring this
| file at the bottom (the group below declares its own middleware, prefix
| and name prefix, so it does not depend on an enclosing group):
|
|     require __DIR__.'/admin/hosting.php';
|
| Route names match the sidebar contract:
|     admin.hosting.*  (index/create/store/show/edit/update/destroy/suspend/unsuspend/change-package/pull-ip/choose-ip/release-ip)
|     admin.servers.*  (index/show/create/store/edit/update)
|     admin.server-groups.* (index/create/store/edit/update)
|
| Permission gates: hosting.view for reads, hosting.manage for writes. The
| reference granular hosting.create/edit/delete permissions and the task's
| hosting.server_groups permission are NOT seeded locally, so all write
| actions (including server groups) are gated by hosting.manage.
|
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // --- Hosting accounts ---
    Route::get('hosting', [HostingController::class, 'index'])
        ->middleware('permission:hosting.view')
        ->name('hosting.index');
    Route::get('hosting/create', [HostingController::class, 'create'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.create');
    Route::post('hosting', [HostingController::class, 'store'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.store');
    Route::get('hosting/{hostingAccount}', [HostingController::class, 'show'])
        ->middleware('permission:hosting.view')
        ->name('hosting.show');
    Route::get('hosting/{hostingAccount}/edit', [HostingController::class, 'edit'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.edit');
    Route::put('hosting/{hostingAccount}', [HostingController::class, 'update'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.update');
    Route::delete('hosting/{hostingAccount}', [HostingController::class, 'destroy'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.destroy');

    // Lifecycle actions (POST buttons on the show page)
    Route::post('hosting/{hostingAccount}/suspend', [HostingController::class, 'suspend'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.suspend');
    Route::post('hosting/{hostingAccount}/unsuspend', [HostingController::class, 'unsuspend'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.unsuspend');
    Route::post('hosting/{hostingAccount}/change-package', [HostingController::class, 'changePackage'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.change-package');

    // IP lease actions (IP address card on the show page)
    Route::post('hosting/{hostingAccount}/pull-ip', [HostingController::class, 'pullIp'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.pull-ip');
    Route::post('hosting/{hostingAccount}/choose-ip', [HostingController::class, 'chooseIp'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.choose-ip');
    Route::post('hosting/{hostingAccount}/release-ip', [HostingController::class, 'releaseIp'])
        ->middleware('permission:hosting.manage')
        ->name('hosting.release-ip');

    // --- Servers ---
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
        ->middleware('permission:hosting.view')
        ->name('server-groups.index');
    Route::get('server-groups/create', [ServerGroupController::class, 'create'])
        ->middleware('permission:hosting.manage')
        ->name('server-groups.create');
    Route::post('server-groups', [ServerGroupController::class, 'store'])
        ->middleware('permission:hosting.manage')
        ->name('server-groups.store');
    Route::get('server-groups/{serverGroup}/edit', [ServerGroupController::class, 'edit'])
        ->middleware('permission:hosting.manage')
        ->name('server-groups.edit');
    Route::put('server-groups/{serverGroup}', [ServerGroupController::class, 'update'])
        ->middleware('permission:hosting.manage')
        ->name('server-groups.update');
});
