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
        // Authorization mirrors routes/admin/ssl.php: reads are `hosting.view`,
        // writes are `settings.edit`.
        Route::get('/', [SslController::class, 'index'])
            ->middleware('permission:hosting.view');
        Route::post('/', [SslController::class, 'store'])
            ->middleware('permission:settings.edit');
        Route::get('{ssl}', [SslController::class, 'show'])
            ->middleware('permission:hosting.view');
        Route::put('{ssl}', [SslController::class, 'update'])
            ->middleware('permission:settings.edit');
        Route::delete('{ssl}', [SslController::class, 'destroy'])
            ->middleware('permission:settings.edit');
    });
});
