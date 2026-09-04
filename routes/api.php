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

// Authorization mirrors the admin web twin (routes/admin/customers.* -> the
// `customers.*` permissions). `auth:sanctum` alone is NOT sufficient: a token
// is issued to a user, not to a role, so without these gates any token holder
// — including a client-portal customer — reaches staff-only endpoints.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.view');
    Route::post('customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.view');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.edit');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware('permission:customers.delete');
});
