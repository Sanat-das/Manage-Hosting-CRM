<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders REST API (Sanctum) — Session 3A.2
|--------------------------------------------------------------------------
| Mirrors the reference /api/orders endpoints. Self-contained: wired via
| bootstrap/app.php withRouting(then:) as a bare require, so it declares its
| own 'api' middleware group + 'api' prefix (routes/api.php is not edited).
|
| Endpoints:
|   GET  /api/orders           list (search / status filters, paginated)
|   POST /api/orders           create (pending order + item snapshot)
|   GET  /api/orders/{order}   detail (items, invoices, relations)
|   PUT  /api/orders/{order}/status  guarded status transition
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']);
        Route::get('{order}', [OrderController::class, 'show']);
        Route::put('{order}/status', [OrderController::class, 'updateStatus']);
    });
});
