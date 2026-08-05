<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ServiceInstanceController;
use App\Http\Controllers\Admin\ProvisioningEventController;

/*
|--------------------------------------------------------------------------
| Provisioning engine — admin web routes (Session 3B.5)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.service-instances.*).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('service-instances', [ServiceInstanceController::class, 'index'])
        ->middleware('permission:hosting.view')->name('service-instances.index');
    Route::get('service-instances/{service_instance}', [ServiceInstanceController::class, 'show'])
        ->middleware('permission:hosting.view')->name('service-instances.show');
    Route::put('service-instances/{service_instance}', [ServiceInstanceController::class, 'update'])
        ->middleware('permission:hosting.manage')->name('service-instances.update');
    Route::post('service-instances/{serviceInstance}/suspend', [ServiceInstanceController::class, 'suspend'])
        ->middleware('permission:hosting.manage')->name('service-instances.suspend');
    Route::post('service-instances/{serviceInstance}/terminate', [ServiceInstanceController::class, 'terminate'])
        ->middleware('permission:hosting.manage')->name('service-instances.terminate');

    Route::get('provisioning-events', [ProvisioningEventController::class, 'index'])
        ->middleware('permission:hosting.view')->name('provisioning-events.index');
    Route::get('provisioning-events/{provisioning_event}', [ProvisioningEventController::class, 'show'])
        ->middleware('permission:hosting.view')->name('provisioning-events.show');
});
