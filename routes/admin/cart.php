<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\CartController;

/*
|--------------------------------------------------------------------------
| Shopping cart — admin web routes (Session 3B.1)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| routes are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.cart.*).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('cart', [CartController::class, 'index'])
        ->middleware('permission:orders.view')->name('cart.index');
    Route::post('cart/add', [CartController::class, 'addToCart'])
        ->middleware('permission:orders.create')->name('cart.add');
    Route::post('cart/remove', [CartController::class, 'removeFromCart'])
        ->middleware('permission:orders.create')->name('cart.remove');
    Route::get('cart/checkout', [CartController::class, 'checkout'])
        ->middleware('permission:orders.create')->name('cart.checkout');
    Route::get('cart/domain-search', [CartController::class, 'domainSearch'])
        ->middleware('permission:orders.view')->name('cart.domain-search');
    Route::get('cart/product/{product}', [CartController::class, 'productDetail'])
        ->middleware('permission:orders.view')->name('cart.product');
});
