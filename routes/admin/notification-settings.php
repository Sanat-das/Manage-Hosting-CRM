<?php

use App\Http\Controllers\Admin\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin notification settings routes (admin.notification-settings.*)
|--------------------------------------------------------------------------
|
| Self-contained route file for the centralized notification matrix page
| (event x audience x channel). Distinct from routes/admin/notifications.php,
| which serves the per-user inbox.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('notification-settings', [NotificationSettingsController::class, 'index'])->middleware('permission:settings.view')->name('notification-settings.index');
    Route::put('notification-settings', [NotificationSettingsController::class, 'update'])->middleware('permission:settings.manage')->name('notification-settings.update');
});
