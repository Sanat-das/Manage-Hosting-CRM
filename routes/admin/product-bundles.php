<?php

use App\Http\Controllers\Admin\ProductBundleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin product bundles routes (Tier 4.4)
|--------------------------------------------------------------------------
| Filled from the placeholder. Mirrors the group shape in
| routes/admin/enterprise.php: standard admin group (web + auth + admin),
| reads gated by product-bundles.view, writes by product-bundles.manage.
| Fallback to hosting.* handled in PermissionMiddleware.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('product-bundles', [ProductBundleController::class, 'index'])
        ->middleware('permission:product-bundles.view')->name('product-bundles.index');
    Route::get('product-bundles/create', [ProductBundleController::class, 'create'])
        ->middleware('permission:product-bundles.manage')->name('product-bundles.create');
    Route::post('product-bundles', [ProductBundleController::class, 'store'])
        ->middleware('permission:product-bundles.manage')->name('product-bundles.store');
    Route::get('product-bundles/{product}', [ProductBundleController::class, 'show'])
        ->middleware('permission:product-bundles.view')->name('product-bundles.show');
    Route::get('product-bundles/{product}/edit', [ProductBundleController::class, 'edit'])
        ->middleware('permission:product-bundles.manage')->name('product-bundles.edit');
    Route::put('product-bundles/{product}', [ProductBundleController::class, 'update'])
        ->middleware('permission:product-bundles.manage')->name('product-bundles.update');
    Route::delete('product-bundles/{product}', [ProductBundleController::class, 'destroy'])
        ->middleware('permission:product-bundles.manage')->name('product-bundles.destroy');
});
