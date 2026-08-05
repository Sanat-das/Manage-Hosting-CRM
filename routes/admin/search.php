<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\SearchController;

/*
|--------------------------------------------------------------------------
| Global search — admin web route (Session 5.3)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so this
| route is NOT publicly reachable.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('search', [SearchController::class, 'search'])
        ->middleware('permission:dashboard.view')->name('search.index');
});
