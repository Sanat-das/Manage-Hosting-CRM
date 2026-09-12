<?php

use App\Http\Controllers\Client\ChatGuestAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer chat routes (no authentication)
|--------------------------------------------------------------------------
| Self-contained route file - wired in bootstrap/app.php via withRouting(then:).
|
| These are reachable by a visitor with no account, so every one of them
| authorises from the conversation's own guest token and nothing else. They
| carry `web` for the session and CSRF token the widget posts with, and a
| throttle, because an unauthenticated endpoint that mints rows is exactly what
| gets hammered.
*/

Route::middleware(['web', 'throttle:30,1'])->prefix('chat')->name('chat.')->group(function () {
    Route::post('guest-auth', ChatGuestAuthController::class)->name('guest-auth');
});
