<?php

use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\QuoteController;
use App\Http\Controllers\Admin\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Billing routes (Session 3A.1)
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.invoices.index / create / show / edit / pdf
|   - admin.payments.index / create / show
|   - admin.quotes.index / create / show / edit
|   - admin.transactions.index / show
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->middleware('permission:invoices.view')
        ->name('invoices.index');

    Route::get('invoices/create', [InvoiceController::class, 'create'])
        ->middleware('permission:invoices.create')
        ->name('invoices.create');

    Route::post('invoices', [InvoiceController::class, 'store'])
        ->middleware('permission:invoices.create')
        ->name('invoices.store');

    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->middleware('permission:invoices.view')
        ->name('invoices.show');

    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->middleware('permission:invoices.edit')
        ->name('invoices.edit');

    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
        ->middleware('permission:invoices.edit')
        ->name('invoices.update');

    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->middleware('permission:invoices.view')
        ->name('invoices.pdf');

    // Payments
    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view')
        ->name('payments.index');

    Route::get('payments/create', [PaymentController::class, 'create'])
        ->middleware('permission:payments.create')
        ->name('payments.create');

    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.create')
        ->name('payments.store');

    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('permission:payments.view')
        ->name('payments.show');

    // Quotes
    Route::get('quotes', [QuoteController::class, 'index'])
        ->middleware('permission:invoices.view')
        ->name('quotes.index');

    Route::get('quotes/create', [QuoteController::class, 'create'])
        ->middleware('permission:invoices.create')
        ->name('quotes.create');

    Route::post('quotes', [QuoteController::class, 'store'])
        ->middleware('permission:invoices.create')
        ->name('quotes.store');

    Route::get('quotes/{quote}', [QuoteController::class, 'show'])
        ->middleware('permission:invoices.view')
        ->name('quotes.show');

    Route::get('quotes/{quote}/edit', [QuoteController::class, 'edit'])
        ->middleware('permission:invoices.edit')
        ->name('quotes.edit');

    Route::put('quotes/{quote}', [QuoteController::class, 'update'])
        ->middleware('permission:invoices.edit')
        ->name('quotes.update');

    Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])
        ->middleware('permission:invoices.delete')
        ->name('quotes.destroy');

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index'])
        ->middleware('permission:invoices.view')
        ->name('transactions.index');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])
        ->middleware('permission:invoices.view')
        ->name('transactions.show');
});
