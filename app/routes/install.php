<?php

use App\Http\Controllers\Install\InstallerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| First-run installer
|--------------------------------------------------------------------------
|
| The wizard is only reachable while the application is not installed yet.
| App\Http\Middleware\EnsureAppInstalled (appended to the "web" group in
| bootstrap/app.php) sends every other web request here, while
| App\Http\Middleware\RedirectIfInstalled keeps these routes locked once
| the installation has been completed.
|
*/

Route::middleware(['web', 'redirect.if.installed'])->group(function () {
    Route::get('/install', [InstallerController::class, 'index'])->name('install.index');
    Route::post('/install', [InstallerController::class, 'store'])
        ->middleware('throttle:install')
        ->name('install.run');
});

// The success screen must be reachable in the request after the install
// completes (at that point APP_INSTALLED is already true).
Route::middleware(['web'])->group(function () {
    Route::get('/install/complete', [InstallerController::class, 'complete'])->name('install.complete');
});
