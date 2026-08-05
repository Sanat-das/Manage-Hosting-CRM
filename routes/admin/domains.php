<?php

use App\Http\Controllers\Admin\DomainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Domains routes
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('domains', [DomainController::class, 'index'])
        ->middleware('permission:domains.view')
        ->name('domains.index');

    Route::get('domains/create', [DomainController::class, 'create'])
        ->middleware('permission:domains.manage')
        ->name('domains.create');

    Route::post('domains', [DomainController::class, 'store'])
        ->middleware('permission:domains.manage')
        ->name('domains.store');

    Route::get('domains/search', [DomainController::class, 'search'])
        ->middleware('permission:domains.manage')
        ->name('domains.search');

    Route::get('domains/{domain}', [DomainController::class, 'show'])
        ->middleware('permission:domains.view')
        ->name('domains.show');

    Route::get('domains/{domain}/edit', [DomainController::class, 'edit'])
        ->middleware('permission:domains.manage')
        ->name('domains.edit');

    Route::put('domains/{domain}', [DomainController::class, 'update'])
        ->middleware('permission:domains.manage')
        ->name('domains.update');

    Route::delete('domains/{domain}', [DomainController::class, 'destroy'])
        ->middleware('permission:domains.manage')
        ->name('domains.destroy');
});
