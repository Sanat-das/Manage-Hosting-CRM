<?php

use App\Http\Controllers\Admin\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Orders routes (Session 3A.2)
|--------------------------------------------------------------------------
| Self-contained route file — NOT loaded from routes/web.php. It is wired in
| bootstrap/app.php via withRouting(then:) and carries its own middleware /
| prefix / name group so the wiring task can register it with a bare require.
|
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.orders.index   (All Orders)
|   - admin.orders.create  (New Order)
|
| NB: `orders/create` must be registered before `orders/{order}`.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])
        ->middleware('permission:orders.view')
        ->name('orders.index');

    Route::get('orders/create', [OrderController::class, 'create'])
        ->middleware('permission:orders.create')
        ->name('orders.create');

    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('permission:orders.create')
        ->name('orders.store');

    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->middleware('permission:orders.view')
        ->name('orders.show');

    Route::put('orders/{order}/status', [OrderController::class, 'updateStatus'])
        ->middleware('permission:orders.edit')
        ->name('orders.status');
});
