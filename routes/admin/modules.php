<?php

use App\Http\Controllers\Admin\ModuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Modules routes
|--------------------------------------------------------------------------
|
| Self-contained route file wired in bootstrap/app.php via withRouting(then:).
|
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.modules.index, admin.modules.install
|   - admin.modules.activate, admin.modules.deactivate, admin.modules.uninstall
|   - admin.modules.config, admin.modules.config.update
|
| Permission gates:
|   - modules: view (index), manage (install/activate/deactivate/uninstall/config)
|
| Note: {module} binds App\Models\Module via implicit model binding. All
| state-changing routes are POST/PUT method-form submits (@csrf).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Module list (WP-style plugin grid)
    Route::get('modules', [ModuleController::class, 'index'])
        ->middleware('permission:modules.view')
        ->name('modules.index');

    // Install a module from an uploaded ZIP (field: module_zip)
    Route::post('modules/install', [ModuleController::class, 'install'])
        ->middleware('permission:modules.manage')
        ->name('modules.install');

    // Lifecycle
    Route::post('modules/{module}/activate', [ModuleController::class, 'activate'])
        ->middleware('permission:modules.manage')
        ->name('modules.activate');

    Route::post('modules/{module}/deactivate', [ModuleController::class, 'deactivate'])
        ->middleware('permission:modules.manage')
        ->name('modules.deactivate');

    Route::post('modules/{module}/uninstall', [ModuleController::class, 'uninstall'])
        ->middleware('permission:modules.manage')
        ->name('modules.uninstall');

    // Per-module configuration
    Route::get('modules/{module}/config', [ModuleController::class, 'config'])
        ->middleware('permission:modules.manage')
        ->name('modules.config');

    Route::put('modules/{module}/config', [ModuleController::class, 'updateConfig'])
        ->middleware('permission:modules.manage')
        ->name('modules.config.update');
});
