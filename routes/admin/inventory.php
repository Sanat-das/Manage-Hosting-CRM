<?php

use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DevicePortController;
use App\Http\Controllers\Admin\DeviceTypeController;
use App\Http\Controllers\Admin\InventoryItemController;
use App\Http\Controllers\Admin\ManufacturerController;
use App\Http\Controllers\Admin\PortConnectionController;
use App\Http\Controllers\Admin\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Devices — admin web routes (Session 3B.2)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.devices.*).
|
| Permission gates: granular devices.view/manage as defined in
 | AdminLteRbacSeeder.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('devices', [DeviceController::class, 'index'])
        ->middleware('permission:devices.view')->name('devices.index');
    Route::get('devices/export', [DeviceController::class, 'export'])
        ->middleware('permission:devices.view')->name('devices.export');
    Route::post('devices/bulk-status', [DeviceController::class, 'bulkStatus'])
        ->middleware('permission:devices.manage')->name('devices.bulk-status');
    Route::post('devices/bulk-edit', [DeviceController::class, 'bulkEdit'])
        ->middleware('permission:devices.manage')->name('devices.bulk-edit');
    Route::get('devices/create', [DeviceController::class, 'create'])
        ->middleware('permission:devices.manage')->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])
        ->middleware('permission:devices.manage')->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])
        ->middleware('permission:devices.view')->name('devices.show');
    Route::get('devices/{device}/edit', [DeviceController::class, 'edit'])
        ->middleware('permission:devices.manage')->name('devices.edit');
    Route::put('devices/{device}', [DeviceController::class, 'update'])
        ->middleware('permission:devices.manage')->name('devices.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])
        ->middleware('permission:devices.manage')->name('devices.destroy');
    Route::delete('devices/{device}/ip-addresses/{ipAddress}', [DeviceController::class, 'detachIp'])
        ->middleware('permission:devices.manage')->name('devices.detach-ip');
    Route::get('inventory-items', [InventoryItemController::class, 'index'])
        ->middleware('permission:devices.view')->name('inventory-items.index');
    Route::get('inventory-items/create', [InventoryItemController::class, 'create'])
        ->middleware('permission:devices.manage')->name('inventory-items.create');
    Route::post('inventory-items', [InventoryItemController::class, 'store'])
        ->middleware('permission:devices.manage')->name('inventory-items.store');
    Route::get('inventory-items/{inventoryItem}', [InventoryItemController::class, 'show'])
        ->middleware('permission:devices.view')->name('inventory-items.show');
    Route::get('inventory-items/{inventoryItem}/edit', [InventoryItemController::class, 'edit'])
        ->middleware('permission:devices.manage')->name('inventory-items.edit');
    Route::put('inventory-items/{inventoryItem}', [InventoryItemController::class, 'update'])
        ->middleware('permission:devices.manage')->name('inventory-items.update');
    Route::delete('inventory-items/{inventoryItem}', [InventoryItemController::class, 'destroy'])
        ->middleware('permission:devices.manage')->name('inventory-items.destroy');

    Route::get('manufacturers', [ManufacturerController::class, 'index'])
        ->middleware('permission:devices.view')->name('manufacturers.index');
    Route::get('manufacturers/create', [ManufacturerController::class, 'create'])
        ->middleware('permission:devices.manage')->name('manufacturers.create');
    Route::post('manufacturers', [ManufacturerController::class, 'store'])
        ->middleware('permission:devices.manage')->name('manufacturers.store');
    Route::get('manufacturers/{manufacturer}/edit', [ManufacturerController::class, 'edit'])
        ->middleware('permission:devices.manage')->name('manufacturers.edit');
    Route::put('manufacturers/{manufacturer}', [ManufacturerController::class, 'update'])
        ->middleware('permission:devices.manage')->name('manufacturers.update');
    Route::delete('manufacturers/{manufacturer}', [ManufacturerController::class, 'destroy'])
        ->middleware('permission:devices.manage')->name('manufacturers.destroy');

    Route::get('device-types', [DeviceTypeController::class, 'index'])
        ->middleware('permission:devices.view')->name('device-types.index');
    Route::get('device-types/create', [DeviceTypeController::class, 'create'])
        ->middleware('permission:devices.manage')->name('device-types.create');
    Route::post('device-types', [DeviceTypeController::class, 'store'])
        ->middleware('permission:devices.manage')->name('device-types.store');
    Route::get('device-types/{device_type}', [DeviceTypeController::class, 'show'])
        ->middleware('permission:devices.view')->name('device-types.show');
    Route::get('device-types/{device_type}/edit', [DeviceTypeController::class, 'edit'])
        ->middleware('permission:devices.manage')->name('device-types.edit');
    Route::put('device-types/{device_type}', [DeviceTypeController::class, 'update'])
        ->middleware('permission:devices.manage')->name('device-types.update');
    Route::delete('device-types/{device_type}', [DeviceTypeController::class, 'destroy'])
        ->middleware('permission:devices.manage')->name('device-types.destroy');

    Route::get('tags', [TagController::class, 'index'])
        ->middleware('permission:devices.view')->name('tags.index');
    Route::get('tags/create', [TagController::class, 'create'])
        ->middleware('permission:devices.manage')->name('tags.create');
    Route::post('tags', [TagController::class, 'store'])
        ->middleware('permission:devices.manage')->name('tags.store');
    Route::get('tags/{tag}/edit', [TagController::class, 'edit'])
        ->middleware('permission:devices.manage')->name('tags.edit');
    Route::put('tags/{tag}', [TagController::class, 'update'])
        ->middleware('permission:devices.manage')->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])
        ->middleware('permission:devices.manage')->name('tags.destroy');

    Route::get('ports/search', [DevicePortController::class, 'search'])
        ->middleware('permission:ports.view')->name('ports.search');
    Route::post('devices/{device}/ports', [DevicePortController::class, 'store'])
        ->middleware('permission:ports.manage')->name('devices.ports.store');
    Route::put('devices/{device}/ports/{devicePort}', [DevicePortController::class, 'update'])
        ->middleware('permission:ports.manage')->name('devices.ports.update');
    Route::delete('devices/{device}/ports/{devicePort}', [DevicePortController::class, 'destroy'])
        ->middleware('permission:ports.manage')->name('devices.ports.destroy');
    Route::post('devices/{device}/port-connections', [PortConnectionController::class, 'store'])
        ->middleware('permission:ports.manage')->name('devices.port-connections.store');
    Route::get('port-connections', [PortConnectionController::class, 'index'])
        ->middleware('permission:ports.view')->name('port-connections.index');
    Route::get('port-connections/export', [PortConnectionController::class, 'export'])
        ->middleware('permission:ports.view')->name('port-connections.export');
    Route::put('port-connections/{portConnection}', [PortConnectionController::class, 'update'])
        ->middleware('permission:ports.manage')->name('port-connections.update');
    Route::delete('port-connections/{portConnection}', [PortConnectionController::class, 'destroy'])
        ->middleware('permission:ports.manage')->name('port-connections.destroy');
});
