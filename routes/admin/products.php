<?php

use App\Http\Controllers\Admin\AddonController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ProductModuleController;
use App\Http\Controllers\Admin\ProductOptionController;
use App\Http\Controllers\Admin\ProductOptionLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Products routes
|--------------------------------------------------------------------------
|
| Self-contained route file wired in bootstrap/app.php via withRouting(then:).
|
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.products.index, admin.products.create
|   - admin.product-groups.index, admin.product-options.index, admin.addons.index
|
| Permission gates:
|   - products: view (index/show), create (create/store),
|     edit (edit/update/destroy + option links + product modules),
|     delete (destroy)
|   - product-groups / product-options / addons: single products.* gate each
|
| Product module routes (enable/disable + per-product config on the show
| page) live under products/{product}/modules/... (see
| ProductModuleController).
|
| Note: /create routes must be registered before /{resource} routes.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Products
    Route::get('products', [ProductController::class, 'index'])
        ->middleware('permission:products.view')
        ->name('products.index');

    Route::get('products/create', [ProductController::class, 'create'])
        ->middleware('permission:products.create')
        ->name('products.create');

    Route::post('products', [ProductController::class, 'store'])
        ->middleware('permission:products.create')
        ->name('products.store');

    Route::get('products/{product}', [ProductController::class, 'show'])
        ->middleware('permission:products.view')
        ->name('products.show');

    Route::get('products/{product}/edit', [ProductController::class, 'edit'])
        ->middleware('permission:products.edit')
        ->name('products.edit');

    Route::put('products/{product}', [ProductController::class, 'update'])
        ->middleware('permission:products.edit')
        ->name('products.update');

    Route::delete('products/{product}', [ProductController::class, 'destroy'])
        ->middleware('permission:products.delete')
        ->name('products.destroy');

    // Product option links (attach/sync/detach a group snapshot on a product;
    // per-link value edits save through the single product update form).
    Route::post('products/{product}/options/attach', [ProductOptionLinkController::class, 'attach'])
        ->middleware('permission:products.edit')
        ->name('products.options.attach');

    Route::delete('products/{product}/options/{link}', [ProductOptionLinkController::class, 'destroy'])
        ->middleware('permission:products.edit')
        ->name('products.options.detach');

    Route::post('products/{product}/options/{link}/sync', [ProductOptionLinkController::class, 'sync'])
        ->middleware('permission:products.edit')
        ->name('products.options.sync');

    // Product modules (enable/disable + per-product config on the show page;
    // the pivot ProductModule stores the per-product enabled flag and config).
    Route::post('products/{product}/modules/{module}/toggle', [ProductModuleController::class, 'toggle'])
        ->middleware('permission:products.edit')
        ->name('products.modules.toggle');

    Route::put('products/{product}/modules/{module}/config', [ProductModuleController::class, 'updateConfig'])
        ->middleware('permission:products.edit')
        ->name('products.modules.config');

    // Product groups
    Route::get('product-groups', [ProductGroupController::class, 'index'])
        ->middleware('permission:products.groups')
        ->name('product-groups.index');

    Route::get('product-groups/create', [ProductGroupController::class, 'create'])
        ->middleware('permission:products.groups')
        ->name('product-groups.create');

    Route::post('product-groups', [ProductGroupController::class, 'store'])
        ->middleware('permission:products.groups')
        ->name('product-groups.store');

    Route::get('product-groups/{productGroup}/edit', [ProductGroupController::class, 'edit'])
        ->middleware('permission:products.groups')
        ->name('product-groups.edit');

    Route::put('product-groups/{productGroup}', [ProductGroupController::class, 'update'])
        ->middleware('permission:products.groups')
        ->name('product-groups.update');

    Route::delete('product-groups/{productGroup}', [ProductGroupController::class, 'destroy'])
        ->middleware('permission:products.groups')
        ->name('product-groups.destroy');

    // Configurable options (groups → values → per-cycle pricing)
    Route::get('product-options', [ProductOptionController::class, 'index'])
        ->middleware('permission:products.options')
        ->name('product-options.index');

    Route::get('product-options/create', [ProductOptionController::class, 'create'])
        ->middleware('permission:products.options')
        ->name('product-options.create');

    Route::post('product-options', [ProductOptionController::class, 'store'])
        ->middleware('permission:products.options')
        ->name('product-options.store');

    Route::get('product-options/{productOption}/edit', [ProductOptionController::class, 'edit'])
        ->middleware('permission:products.options')
        ->name('product-options.edit');

    Route::put('product-options/{productOption}', [ProductOptionController::class, 'update'])
        ->middleware('permission:products.options')
        ->name('product-options.update');

    Route::delete('product-options/{productOption}', [ProductOptionController::class, 'destroy'])
        ->middleware('permission:products.options')
        ->name('product-options.destroy');

    // Add-ons (product-scoped or global when product_id is null)
    Route::get('addons', [AddonController::class, 'index'])
        ->middleware('permission:products.addons')
        ->name('addons.index');

    Route::get('addons/create', [AddonController::class, 'create'])
        ->middleware('permission:products.addons')
        ->name('addons.create');

    Route::post('addons', [AddonController::class, 'store'])
        ->middleware('permission:products.addons')
        ->name('addons.store');

    Route::get('addons/{addon}/edit', [AddonController::class, 'edit'])
        ->middleware('permission:products.addons')
        ->name('addons.edit');

    Route::put('addons/{addon}', [AddonController::class, 'update'])
        ->middleware('permission:products.addons')
        ->name('addons.update');

    Route::delete('addons/{addon}', [AddonController::class, 'destroy'])
        ->middleware('permission:products.addons')
        ->name('addons.destroy');
});
