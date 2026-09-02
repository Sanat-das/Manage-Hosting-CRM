<?php

use App\Http\Controllers\Admin\ProvisioningEventController;
use App\Http\Controllers\Admin\ServiceInstanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provisioning engine — admin web routes (Session 3B.5)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.service-instances.*).
|
| Permission gates: granular service-instances / provisioning-events as
| defined in config/permissions.php. Fallback to hosting.* handled in
| PermissionMiddleware.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('service-instances', [ServiceInstanceController::class, 'index'])
        ->middleware('permission:service-instances.view')->name('service-instances.index');
    Route::get('service-instances/{service_instance}', [ServiceInstanceController::class, 'show'])
        ->middleware('permission:service-instances.view')->name('service-instances.show');
    Route::put('service-instances/{service_instance}', [ServiceInstanceController::class, 'update'])
        ->middleware('permission:service-instances.manage')->name('service-instances.update');
    Route::post('service-instances/{serviceInstance}/suspend', [ServiceInstanceController::class, 'suspend'])
        ->middleware('permission:service-instances.manage')->name('service-instances.suspend');
    Route::post('service-instances/{serviceInstance}/terminate', [ServiceInstanceController::class, 'terminate'])
        ->middleware('permission:service-instances.manage')->name('service-instances.terminate');

    Route::get('provisioning-events', [ProvisioningEventController::class, 'index'])
        ->middleware('permission:provisioning-events.view')->name('provisioning-events.index');
    Route::get('provisioning-events/{provisioning_event}', [ProvisioningEventController::class, 'show'])
        ->middleware('permission:provisioning-events.view')->name('provisioning-events.show');
});
