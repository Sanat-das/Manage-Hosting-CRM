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
| carry `web` for the session and CSRF token the widget posts with.
|
| Throttling is split three ways rather than applied once to the group. A guest
| has no user id, so every limit here is keyed on an IP that a whole office may
| share, and the widget's own background traffic — a poll every 6 seconds plus a
| typing heartbeat — is chatty enough that one customer could exhaust a single
| modest budget by themselves. The limits are therefore sized by what each
| endpoint actually costs; see AppServiceProvider::boot() for the numbers and
| the reasoning behind each.
*/

Route::middleware(['web'])->prefix('chat')->name('chat.')->group(function () {
    // Creates a conversation row. Tightest of the three.
    Route::post('start', [ChatWidgetController::class, 'start'])
        ->middleware('throttle:chat-start')
        ->name('start');

    // An out-of-hours message. Creates a TICKET row, so it is budgeted with
    // `start` rather than with the read traffic below — the two are mutually
    // exclusive (one is refused whenever the other is allowed) and share the
    // same "this visitor is opening something" cost.
    Route::post('offline', [ChatWidgetController::class, 'offline'])
        ->middleware('throttle:chat-start')
        ->name('offline');

    // Writes up to 10MB to disk. Kept off the generous limit below.
    Route::post('{conversation}/messages/{message}/attachments', [ChatWidgetController::class, 'attach'])
        ->middleware('throttle:chat-attach')
        ->name('attach');

    // The rest of the widget. Every one of these resolves a single conversation
    // and proves the caller belongs to it; there is deliberately no endpoint
    // that LISTS conversations, so a customer cannot discover that others exist.
    Route::middleware('throttle:chat-widget')->group(function () {
        Route::post('guest-auth', ChatGuestAuthController::class)->name('guest-auth');

        // "Are you open?", asked when the widget is opened rather than when the
        // page was rendered — a tab left open past closing time would otherwise
        // still be offering a chat nobody is there to answer. Reads two cached
        // values and no conversation at all, so it belongs on the generous
        // limit with the rest of the widget's background traffic.
        Route::get('availability', [ChatWidgetController::class, 'availability'])->name('availability');
        Route::get('{conversation}/messages', [ChatWidgetController::class, 'messages'])->name('messages');
        Route::post('{conversation}/messages', [ChatWidgetController::class, 'send'])->name('send');
        Route::get('{conversation}/attachments/{attachment}', [ChatWidgetController::class, 'attachment'])
            ->name('attachment');
        Route::post('{conversation}/typing', [ChatWidgetController::class, 'typing'])->name('typing');
        Route::post('{conversation}/rate', [ChatWidgetController::class, 'rate'])->name('rate');
    });
});
