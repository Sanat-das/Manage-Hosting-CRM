<?php

use App\Http\Controllers\Admin\SearchController;
use Illuminate\Support\Facades\Route;

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

    /*
     | JSON typeahead for the command palette: 300/min per user via
     | `throttle:search`. `withoutMiddleware('throttle:admin')` opts out of the
     | group's shared bucket (same precedent as the chat write routes in
     | routes/admin/config.php:154), so one keystroke is charged to a single
     | limiter instead of two. The palette debounces at 250ms, so a sustained
     | typist peaks at ~240 requests/min — under the 300 ceiling.
     */
    Route::get('search/typeahead', [SearchController::class, 'typeahead'])
        ->middleware(['permission:search', 'throttle:search'])
        ->withoutMiddleware('throttle:admin')
        ->name('search.typeahead');
});
