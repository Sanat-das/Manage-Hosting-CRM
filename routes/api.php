<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Sanctum)
|--------------------------------------------------------------------------
|
| Customer CRUD mirroring the reference /api/customers endpoints.
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('customers', [\App\Http\Controllers\Api\CustomerController::class, 'index']);
    Route::post('customers', [\App\Http\Controllers\Api\CustomerController::class, 'store']);
    Route::get('customers/{customer}', [\App\Http\Controllers\Api\CustomerController::class, 'show']);
    Route::put('customers/{customer}', [\App\Http\Controllers\Api\CustomerController::class, 'update']);
    Route::delete('customers/{customer}', [\App\Http\Controllers\Api\CustomerController::class, 'destroy']);
});
