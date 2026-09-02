<?php

use App\Http\Controllers\Admin\TaxRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin tax-rates CRUD — admin web routes (T4.3)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role). All
| routes live under /admin with the admin. name prefix.
|
| Permission gates: granular tax-rates.view/manage as defined in
| config/permissions.php. Fallback to hosting.* handled in PermissionMiddleware.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('tax-rates', [TaxRateController::class, 'index'])
        ->middleware('permission:tax-rates.view')->name('tax-rates.index');
    Route::get('tax-rates/create', [TaxRateController::class, 'create'])
        ->middleware('permission:tax-rates.manage')->name('tax-rates.create');
    Route::post('tax-rates', [TaxRateController::class, 'store'])
        ->middleware('permission:tax-rates.manage')->name('tax-rates.store');
    Route::get('tax-rates/{taxRate}', [TaxRateController::class, 'show'])
        ->middleware('permission:tax-rates.view')->name('tax-rates.show');
    Route::get('tax-rates/{taxRate}/edit', [TaxRateController::class, 'edit'])
        ->middleware('permission:tax-rates.manage')->name('tax-rates.edit');
    Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'update'])
        ->middleware('permission:tax-rates.manage')->name('tax-rates.update');
    Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])
        ->middleware('permission:tax-rates.manage')->name('tax-rates.destroy');
});
