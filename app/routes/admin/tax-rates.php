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
| Permission gates: reads use hosting.view, writes use hosting.manage
| (matching the ResourceTypeController enterprise pattern).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('tax-rates', [TaxRateController::class, 'index'])
        ->middleware('permission:hosting.view')->name('tax-rates.index');
    Route::get('tax-rates/create', [TaxRateController::class, 'create'])
        ->middleware('permission:hosting.manage')->name('tax-rates.create');
    Route::post('tax-rates', [TaxRateController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('tax-rates.store');
    Route::get('tax-rates/{taxRate}', [TaxRateController::class, 'show'])
        ->middleware('permission:hosting.view')->name('tax-rates.show');
    Route::get('tax-rates/{taxRate}/edit', [TaxRateController::class, 'edit'])
        ->middleware('permission:hosting.manage')->name('tax-rates.edit');
    Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'update'])
        ->middleware('permission:hosting.manage')->name('tax-rates.update');
    Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])
        ->middleware('permission:hosting.manage')->name('tax-rates.destroy');
});
