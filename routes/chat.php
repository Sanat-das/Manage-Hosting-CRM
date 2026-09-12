<?php

use App\Http\Controllers\Client\ChatGuestAuthController;
use App\Http\Controllers\Client\ChatWidgetController;
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

    // The customer widget. Every one of these resolves a single conversation
    // and proves the caller belongs to it; there is deliberately no endpoint
    // that LISTS conversations, so a customer cannot discover that others exist.
    Route::post('start', [ChatWidgetController::class, 'start'])->name('start');
    Route::get('{conversation}/messages', [ChatWidgetController::class, 'messages'])->name('messages');
    Route::post('{conversation}/messages', [ChatWidgetController::class, 'send'])->name('send');
    Route::post('{conversation}/messages/{message}/attachments', [ChatWidgetController::class, 'attach'])
        ->name('attach');
    Route::get('{conversation}/attachments/{attachment}', [ChatWidgetController::class, 'attachment'])
        ->name('attachment');
    Route::post('{conversation}/typing', [ChatWidgetController::class, 'typing'])->name('typing');
    Route::post('{conversation}/rate', [ChatWidgetController::class, 'rate'])->name('rate');
});
