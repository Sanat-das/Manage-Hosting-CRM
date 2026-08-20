<?php

use App\Http\Controllers\Admin\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin notification routes (admin.notifications.*)
|--------------------------------------------------------------------------
|
| Self-contained route file for the admin notification inbox. Same
| web/auth/admin middleware stack and `admin` prefix as the other admin
| module files. Admins see only their own database notifications.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.markRead');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.markAllRead');
});
