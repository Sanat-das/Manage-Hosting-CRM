<?php

use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\UpgradeRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin upgrade-request console routes (T4.5)
|--------------------------------------------------------------------------
| Self-contained route file wired in bootstrap/app.php via withRouting(then:).
| Mirrors the group shape of routes/admin/product-upgrades.php: standard admin
| group (web + auth + admin + throttle), reads gated by product-upgrades.view,
| writes by product-upgrades.manage. The manual order upgrade/downgrade form
| lives here too — it shares the same permission pair (product-upgrades.*).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('upgrade-requests', [UpgradeRequestController::class, 'index'])
        ->middleware('permission:product-upgrades.view')->name('upgrade-requests.index');
    Route::get('upgrade-requests/{upgradeRequest}', [UpgradeRequestController::class, 'show'])
        ->middleware('permission:product-upgrades.view')->name('upgrade-requests.show');
    Route::post('upgrade-requests/{upgradeRequest}/approve', [UpgradeRequestController::class, 'approve'])
        ->middleware('permission:product-upgrades.manage')->name('upgrade-requests.approve');
    Route::post('upgrade-requests/{upgradeRequest}/cancel', [UpgradeRequestController::class, 'cancel'])
        ->middleware('permission:product-upgrades.manage')->name('upgrade-requests.cancel');

    // Manual upgrade/downgrade on an order — the admin acts for the customer,
    // so store() places AND approves the request in one step. The flow mirrors
    // the client wizard: Plan (GET) → Configure → Review & Confirm → store.
    Route::get('orders/{order}/upgrade', [OrderController::class, 'upgrade'])
        ->middleware('permission:product-upgrades.view')->name('orders.upgrade');
    Route::post('orders/{order}/upgrade/configure', [OrderController::class, 'configure'])
        ->middleware('permission:product-upgrades.manage')->name('orders.upgrade.configure');
    Route::post('orders/{order}/upgrade/preview', [OrderController::class, 'preview'])
        ->middleware('permission:product-upgrades.manage')->name('orders.upgrade.preview');
    Route::post('orders/{order}/upgrade', [OrderController::class, 'storeUpgrade'])
        ->middleware('permission:product-upgrades.manage')->name('orders.upgrade.store');
});
