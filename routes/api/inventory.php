<?php

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\InventoryItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Devices API (Sanctum)
|--------------------------------------------------------------------------
|
| Self-contained Sanctum-protected routes for the devices module
| (mirrors the Api\SslController endpoints under /api/devices).
| Loaded via bootstrap/app.php `withRouting(..., then:)`.
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('devices')->group(function () {
        // Authorization mirrors routes/admin/inventory.php: reads are
        // `devices.view`, writes are `devices.manage`.
        Route::get('/', [DeviceController::class, 'index'])
            ->middleware('permission:devices.view');
        Route::post('/', [DeviceController::class, 'store'])
            ->middleware('permission:devices.manage');
        Route::get('{device}', [DeviceController::class, 'show'])
            ->middleware('permission:devices.view');
        Route::put('{device}', [DeviceController::class, 'update'])
            ->middleware('permission:devices.manage');
        Route::delete('{device}', [DeviceController::class, 'destroy'])
            ->middleware('permission:devices.manage');
    });

    /*
    |--------------------------------------------------------------------------
    | /api/inventory-assets — PERMANENT alias of /api/devices
    |--------------------------------------------------------------------------
    | Byte-identical duplicate of the devices group above (same middleware,
    | same controller, same {device} parameter, unnamed routes). Kept forever
    | so external Sanctum consumers that still call the pre-rename URL see no
    | break. Not a redirect — response bodies are byte-identical either way.
    | Do NOT add deprecation headers or 410 without its own plan (decision 2,
    | NetBox alignment, 2026-11-19).
    */
    Route::middleware('auth:sanctum')->prefix('inventory-assets')->group(function () {
        // Authorization mirrors routes/admin/inventory.php: reads are
        // `devices.view`, writes are `devices.manage`.
        Route::get('/', [DeviceController::class, 'index'])
            ->middleware('permission:devices.view');
        Route::post('/', [DeviceController::class, 'store'])
            ->middleware('permission:devices.manage');
        Route::get('{device}', [DeviceController::class, 'show'])
            ->middleware('permission:devices.view');
        Route::put('{device}', [DeviceController::class, 'update'])
            ->middleware('permission:devices.manage');
        Route::delete('{device}', [DeviceController::class, 'destroy'])
            ->middleware('permission:devices.manage');
    });

    Route::middleware('auth:sanctum')->prefix('inventory-items')->group(function () {
        Route::get('/', [InventoryItemController::class, 'index'])
            ->middleware('permission:devices.view');
        Route::post('/', [InventoryItemController::class, 'store'])
            ->middleware('permission:devices.manage');
        Route::get('{inventoryItem}', [InventoryItemController::class, 'show'])
            ->middleware('permission:devices.view');
        Route::put('{inventoryItem}', [InventoryItemController::class, 'update'])
            ->middleware('permission:devices.manage');
        Route::delete('{inventoryItem}', [InventoryItemController::class, 'destroy'])
            ->middleware('permission:devices.manage');
    });
});
