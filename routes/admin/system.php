<?php

use App\Http\Controllers\Admin\SystemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin System (About & Updates) routes
|--------------------------------------------------------------------------
|
| Self-contained route file wired in bootstrap/app.php via withRouting(then:).
|
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.system.index
|   - admin.system.check
|   - admin.system.update
|
| Permission gates:
|   - system.view   — view About & Updates page and check for updates
|   - system.update — perform system update (pull + composer + migrate)
|
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('system', [SystemController::class, 'index'])
        ->middleware('permission:system.view')
        ->name('system.index');

    Route::post('system/check', [SystemController::class, 'check'])
        ->middleware('permission:system.view')
        ->name('system.check');

    Route::post('system/update', [SystemController::class, 'update'])
        ->middleware('permission:system.update')
        ->name('system.update');

    Route::get('system/update/progress', [SystemController::class, 'progressStatus'])
        ->middleware('permission:system.update')
        ->name('system.update.progress');

    Route::post('system/rollback', [SystemController::class, 'rollback'])
        ->middleware('permission:system.update')
        ->name('system.rollback');
});
