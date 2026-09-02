<?php

use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\DomainPricingController;
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

    // ── Domain Pricing ──────────────────────────────────────────────────────
    Route::get('domain-pricing', [DomainPricingController::class, 'index'])
        ->middleware('permission:domains.view')
        ->name('domain-pricing.index');

    Route::get('domain-pricing/create', [DomainPricingController::class, 'create'])
        ->middleware('permission:domains.manage')
        ->name('domain-pricing.create');

    Route::post('domain-pricing', [DomainPricingController::class, 'store'])
        ->middleware('permission:domains.manage')
        ->name('domain-pricing.store');

    Route::get('domain-pricing/{domainPricing}', [DomainPricingController::class, 'show'])
        ->middleware('permission:domains.view')
        ->name('domain-pricing.show');

    Route::get('domain-pricing/{domainPricing}/edit', [DomainPricingController::class, 'edit'])
        ->middleware('permission:domains.manage')
        ->name('domain-pricing.edit');

    Route::put('domain-pricing/{domainPricing}', [DomainPricingController::class, 'update'])
        ->middleware('permission:domains.manage')
        ->name('domain-pricing.update');

    Route::delete('domain-pricing/{domainPricing}', [DomainPricingController::class, 'destroy'])
        ->middleware('permission:domains.manage')
        ->name('domain-pricing.destroy');
});
