<?php

use App\Http\Controllers\Api\InventoryAssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory assets API (Sanctum)
|--------------------------------------------------------------------------
|
| Self-contained Sanctum-protected routes for the inventory asset module
| (mirrors the Api\SslController endpoints under /api/inventory-assets).
| Loaded via bootstrap/app.php `withRouting(..., then:)`.
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('inventory-assets')->group(function () {
        // Authorization mirrors routes/admin/inventory.php: reads are
        // `inventory.view`, writes are `inventory.manage`.
        Route::get('/', [InventoryAssetController::class, 'index'])
            ->middleware('permission:inventory.view');
        Route::post('/', [InventoryAssetController::class, 'store'])
            ->middleware('permission:inventory.manage');
        Route::get('{inventoryAsset}', [InventoryAssetController::class, 'show'])
            ->middleware('permission:inventory.view');
        Route::put('{inventoryAsset}', [InventoryAssetController::class, 'update'])
            ->middleware('permission:inventory.manage');
        Route::delete('{inventoryAsset}', [InventoryAssetController::class, 'destroy'])
            ->middleware('permission:inventory.manage');
    });
});
