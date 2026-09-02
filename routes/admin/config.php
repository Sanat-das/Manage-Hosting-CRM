<?php

use App\Http\Controllers\Admin\ChatController;
use App\Http\Controllers\Admin\CustomerGroupController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\GstSettingController;
use App\Http\Controllers\Admin\RegistrarSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Configuration routes (Registrar, GST, Customer Groups, Chat)
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {

    // Registrar Settings
    Route::get('registrar-settings', [RegistrarSettingController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('registrar-settings.index');

    Route::get('registrar-settings/{registrar}/edit', [RegistrarSettingController::class, 'edit'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.edit');

    Route::put('registrar-settings/{registrar}', [RegistrarSettingController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.update');

    Route::delete('registrar-settings/{registrar}', [RegistrarSettingController::class, 'destroy'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.destroy');

    // GST Settings
    Route::get('gst-settings', [GstSettingController::class, 'edit'])
        ->middleware('permission:settings.view')
        ->name('gst-settings.edit');

    Route::put('gst-settings', [GstSettingController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('gst-settings.update');

    // Customer Groups
    Route::resource('customer-groups', CustomerGroupController::class)
        ->parameters(['customer-groups' => 'customerGroup'])
        ->middleware('permission:customers.view');

    // Live Chat
    Route::get('chat', [ChatController::class, 'index'])
        ->middleware('permission:tickets.view')
        ->name('chat.index');

    Route::get('chat/{chat}', [ChatController::class, 'show'])
        ->middleware('permission:tickets.view')
        ->name('chat.show');

    // Payment Gateway Settings
    Route::get('gateway-settings', [GatewayController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('gateway-settings.index');

    Route::get('gateway-settings/{gateway}/edit', [GatewayController::class, 'edit'])
        ->middleware('permission:settings.manage')
        ->name('gateway-settings.edit');

    Route::put('gateway-settings/{gateway}', [GatewayController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('gateway-settings.update');
});
