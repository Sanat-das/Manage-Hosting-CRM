<?php

use App\Http\Controllers\Admin\DevicePortController;
use App\Http\Controllers\Admin\InventoryAssetController;
use App\Http\Controllers\Admin\InventoryTreeController;
use App\Http\Controllers\Admin\PortConnectionController;
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
 | AdminLteRbacSeeder. Fallback to hosting.* handled in PermissionMiddleware.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('inventory-assets', [InventoryAssetController::class, 'index'])
        ->middleware('permission:inventory.view')->name('inventory-assets.index');
    Route::get('inventory-assets/export', [InventoryAssetController::class, 'export'])
        ->middleware('permission:inventory.view')->name('inventory-assets.export');
    Route::get('inventory-assets/search', [InventoryAssetController::class, 'search'])
        ->middleware('permission:inventory.view')->name('inventory-assets.search');
    Route::post('inventory-assets/bulk-status', [InventoryAssetController::class, 'bulkStatus'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.bulk-status');
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
    Route::delete('inventory-assets/{inventoryAsset}/ip-addresses/{ipAddress}', [InventoryAssetController::class, 'detachIp'])
        ->middleware('permission:inventory.manage')->name('inventory-assets.detach-ip');
    Route::get('inventory-tree', [InventoryTreeController::class, 'index'])
        ->middleware('permission:asset-relationships.view')->name('inventory-tree.index');

    Route::get('ports/search', [DevicePortController::class, 'search'])
        ->middleware('permission:ports.view')->name('ports.search');
    Route::post('inventory-assets/{inventoryAsset}/ports', [DevicePortController::class, 'store'])
        ->middleware('permission:ports.manage')->name('inventory-assets.ports.store');
    Route::put('inventory-assets/{inventoryAsset}/ports/{devicePort}', [DevicePortController::class, 'update'])
        ->middleware('permission:ports.manage')->name('inventory-assets.ports.update');
    Route::delete('inventory-assets/{inventoryAsset}/ports/{devicePort}', [DevicePortController::class, 'destroy'])
        ->middleware('permission:ports.manage')->name('inventory-assets.ports.destroy');
    Route::post('inventory-assets/{inventoryAsset}/port-connections', [PortConnectionController::class, 'store'])
        ->middleware('permission:ports.manage')->name('inventory-assets.port-connections.store');
    Route::get('port-connections', [PortConnectionController::class, 'index'])
        ->middleware('permission:ports.view')->name('port-connections.index');
    Route::get('port-connections/export', [PortConnectionController::class, 'export'])
        ->middleware('permission:ports.view')->name('port-connections.export');
    Route::put('port-connections/{portConnection}', [PortConnectionController::class, 'update'])
        ->middleware('permission:ports.manage')->name('port-connections.update');
    Route::delete('port-connections/{portConnection}', [PortConnectionController::class, 'destroy'])
        ->middleware('permission:ports.manage')->name('port-connections.destroy');
});
