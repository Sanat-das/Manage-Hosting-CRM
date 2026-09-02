<?php

use App\Http\Controllers\Admin\InventoryAssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory assets — admin web routes (Session 3B.2)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.inventory-assets.*).
|
| Permission gates: granular inventory.view/manage as defined in
| config/permissions.php. Fallback to hosting.* handled in PermissionMiddleware.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('inventory-assets', [InventoryAssetController::class, 'index'])
        ->middleware('permission:inventory.view')->name('inventory-assets.index');
    Route::get('inventory-assets/create', [InventoryAssetController::class, 'create'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.create');
    Route::post('inventory-assets', [InventoryAssetController::class, 'store'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.store');
    Route::get('inventory-assets/{inventoryAsset}', [InventoryAssetController::class, 'show'])
        ->middleware('permission:inventory.view')->name('inventory-assets.show');
    Route::get('inventory-assets/{inventoryAsset}/edit', [InventoryAssetController::class, 'edit'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.edit');
    Route::put('inventory-assets/{inventoryAsset}', [InventoryAssetController::class, 'update'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.update');
    Route::delete('inventory-assets/{inventoryAsset}', [InventoryAssetController::class, 'destroy'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.destroy');
});
