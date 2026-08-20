<?php

use App\Http\Controllers\Api\CustomerController;
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
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::put('customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
});
