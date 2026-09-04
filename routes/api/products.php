<?php

use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Products REST API (Sanctum) — Session 3A.2
|--------------------------------------------------------------------------
| Mirrors the reference /api/products endpoints. Self-contained: wired via
| bootstrap/app.php withRouting(then:) as a bare require, so it declares its
| own 'api' middleware group + 'api' prefix (routes/api.php is not edited).
|
| Endpoints:
|   GET    /api/products          list (search / status / group filters, paginated)
|   POST   /api/products          create (product row + product_pricing ladder)
|   GET    /api/products/{product}  detail (pricing, options, addons)
|   PUT    /api/products/{product}  update (replaces the pricing ladder)
|   DELETE /api/products/{product}  delete (guarded: active/pending orders block)
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('products')->group(function () {
        // Authorization mirrors routes/admin/products.php (`products.*`).
        Route::get('/', [ProductController::class, 'index'])
            ->middleware('permission:products.view');
        Route::post('/', [ProductController::class, 'store'])
            ->middleware('permission:products.create');
        Route::get('{product}', [ProductController::class, 'show'])
            ->middleware('permission:products.view');
        Route::put('{product}', [ProductController::class, 'update'])
            ->middleware('permission:products.edit');
        Route::delete('{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:products.delete');
    });
});
