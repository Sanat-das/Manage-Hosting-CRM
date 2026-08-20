<?php

use App\Http\Controllers\Admin\ProductUpgradePathController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin product upgrade paths routes (Tier 4.4)
|--------------------------------------------------------------------------
| Filled from the placeholder. Mirrors the group shape in
| routes/admin/enterprise.php: standard admin group (web + auth + admin),
| reads gated by hosting.view, writes by hosting.manage.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('product-upgrades', [ProductUpgradePathController::class, 'index'])
        ->middleware('permission:hosting.view')->name('product-upgrades.index');
    Route::get('product-upgrades/create', [ProductUpgradePathController::class, 'create'])
        ->middleware('permission:hosting.manage')->name('product-upgrades.create');
    Route::post('product-upgrades', [ProductUpgradePathController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('product-upgrades.store');
    Route::get('product-upgrades/{productUpgradePath}', [ProductUpgradePathController::class, 'show'])
        ->middleware('permission:hosting.view')->name('product-upgrades.show');
    Route::get('product-upgrades/{productUpgradePath}/edit', [ProductUpgradePathController::class, 'edit'])
        ->middleware('permission:hosting.manage')->name('product-upgrades.edit');
    Route::put('product-upgrades/{productUpgradePath}', [ProductUpgradePathController::class, 'update'])
        ->middleware('permission:hosting.manage')->name('product-upgrades.update');
    Route::delete('product-upgrades/{productUpgradePath}', [ProductUpgradePathController::class, 'destroy'])
        ->middleware('permission:hosting.manage')->name('product-upgrades.destroy');
});
