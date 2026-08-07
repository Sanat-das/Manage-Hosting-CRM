<?php

use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\HostingController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\KbController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\StoreController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Client\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client Portal routes
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
| All routes require auth + client role middleware.
*/

Route::middleware(['web', 'auth', 'client', 'customer.record'])->prefix('client')->name('client.')->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Store (browse, cart, checkout, order placement)
    Route::get('store', [StoreController::class, 'index'])->name('store.index');
    Route::get('store/cart', [StoreController::class, 'cart'])->name('store.cart');
    Route::get('store/checkout', [StoreController::class, 'checkout'])->name('store.checkout');
    Route::get('store/order/{order}', [StoreController::class, 'confirmation'])->name('store.confirmation');
    Route::post('store/cart/add', [StoreController::class, 'addToCart'])->name('store.cart.add');
    Route::post('store/cart/update', [StoreController::class, 'updateCart'])->name('store.cart.update');
    Route::post('store/cart/remove', [StoreController::class, 'removeFromCart'])->name('store.cart.remove');
    Route::post('store/checkout', [StoreController::class, 'placeOrder'])->name('store.checkout.post');
    Route::get('store/{product}', [StoreController::class, 'show'])->name('store.show');

    // Hosting
    Route::get('hosting', [HostingController::class, 'index'])->name('hosting.index');
    Route::get('hosting/{id}', [HostingController::class, 'show'])->name('hosting.show');

    // Domains
    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/{id}', [DomainController::class, 'show'])->name('domains.show');

    // Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    // Payments
    Route::get('invoices/{invoice}/pay', [PaymentController::class, 'show'])->name('invoices.pay');
    Route::post('invoices/{invoice}/pay', [PaymentController::class, 'purchase'])
        ->middleware('throttle:payments')
        ->name('invoices.pay.purchase');
    Route::get('invoices/{invoice}/pay/return', [PaymentController::class, 'returned'])->name('invoices.pay.return');
    Route::get('payments/{payment}/pending', [PaymentController::class, 'pending'])->name('payments.pending');

    // Tickets
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{id}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{id}/reply', [TicketController::class, 'reply'])->name('tickets.reply');

    // Knowledge Base
    Route::get('kb', [KbController::class, 'index'])->name('kb.index');
    Route::get('kb/{slug}', [KbController::class, 'show'])->name('kb.show');

    // Wallet
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Profile
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
});
