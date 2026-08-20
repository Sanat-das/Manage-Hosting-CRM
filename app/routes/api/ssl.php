<?php

use App\Http\Controllers\Api\SslController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SSL certificates API (Sanctum)
|--------------------------------------------------------------------------
|
| Self-contained Sanctum-protected routes for the SSL certificate module
| (mirrors the Api\CustomerController endpoints under /api/ssl). Loaded via
| bootstrap/app.php `withRouting(..., then:)`.
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('ssl')->group(function () {
        Route::get('/', [SslController::class, 'index']);
        Route::post('/', [SslController::class, 'store']);
        Route::get('{ssl}', [SslController::class, 'show']);
        Route::put('{ssl}', [SslController::class, 'update']);
        Route::delete('{ssl}', [SslController::class, 'destroy']);
    });
});
