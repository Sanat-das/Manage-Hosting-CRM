<?php

use App\Http\Controllers\Admin\ChatController;
use App\Http\Controllers\Admin\CustomerGroupController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\GstSettingController;
use App\Http\Controllers\Admin\RegistrarSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Configuration routes (Registrar, GST, Customer Groups, Chat)
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {

    // Registrar Settings
    Route::get('registrar-settings', [RegistrarSettingController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('registrar-settings.index');

    Route::get('registrar-settings/{registrar}/edit', [RegistrarSettingController::class, 'edit'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.edit');

    Route::put('registrar-settings/{registrar}', [RegistrarSettingController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.update');

    Route::delete('registrar-settings/{registrar}', [RegistrarSettingController::class, 'destroy'])
        ->middleware('permission:settings.manage')
        ->name('registrar-settings.destroy');

    // GST Settings
    Route::get('gst-settings', [GstSettingController::class, 'edit'])
        ->middleware('permission:settings.view')
        ->name('gst-settings.edit');

    Route::put('gst-settings', [GstSettingController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('gst-settings.update');

    // Customer Groups
    Route::resource('customer-groups', CustomerGroupController::class)
        ->parameters(['customer-groups' => 'customerGroup'])
        ->middleware('permission:customers.view');

    // Live Chat
    Route::get('chat', [ChatController::class, 'index'])
        ->middleware('permission:chat.view')
        ->name('chat.index');

    /*
     | The Slack-like engine. Registered BEFORE the legacy `chat/{chat}`
     | wildcard below: that route would otherwise match `chat/channels` and try
     | to resolve "channels" as a ChatSession id.
     |
     | Every route carries permission:chat.view; per-conversation authorisation
     | is ChatConversationPolicy's, applied in the controller. Holding chat.view
     | is permission to use the chat, not permission to read any given room.
     */
    Route::middleware('permission:chat.view')->group(function () {
        Route::post('chat/channels', [ChatController::class, 'storeChannel'])->name('chat.channels.store');
        Route::put('chat/channels/{conversation}', [ChatController::class, 'updateChannel'])->name('chat.channels.update');
        Route::post('chat/channels/{conversation}/archive', [ChatController::class, 'archiveChannel'])->name('chat.channels.archive');
        Route::post('chat/channels/{conversation}/unarchive', [ChatController::class, 'unarchiveChannel'])->name('chat.channels.unarchive');
        Route::post('chat/channels/{conversation}/join', [ChatController::class, 'joinChannel'])->name('chat.channels.join');
        Route::post('chat/channels/{conversation}/leave', [ChatController::class, 'leaveChannel'])->name('chat.channels.leave');
        Route::post('chat/channels/{conversation}/members', [ChatController::class, 'addMember'])->name('chat.channels.members.store');
        Route::delete('chat/channels/{conversation}/members/{user}', [ChatController::class, 'removeMember'])->name('chat.channels.members.destroy');

        Route::get('chat/conversations/{conversation}/messages', [ChatController::class, 'fetchMessages'])->name('chat.messages.index');
        Route::get('chat/conversations/{conversation}/threads/{parent}', [ChatController::class, 'fetchThread'])->name('chat.threads.show');
        Route::post('chat/conversations/{conversation}/typing', [ChatController::class, 'typingHeartbeat'])->name('chat.typing');
        Route::post('chat/conversations/{conversation}/read', [ChatController::class, 'markRead'])->name('chat.read');

        // Message search. chat.view is permission to search one's OWN readable
        // rooms; the conversation whitelist is resolved inside the controller
        // from ChatConversationPolicy before the query touches any message.
        Route::get('chat/search', [ChatController::class, 'search'])->name('chat.search');

        // Entity references in the composer. Each type is additionally gated on
        // the permission that guards its own screen (ChatEntitySearch).
        Route::get('chat/search-entities', [ChatController::class, 'searchEntities'])->name('chat.search-entities');
        Route::post('chat/messages/{message}/entity-links', [ChatController::class, 'storeEntityLink'])->name('chat.entity-links.store');
        Route::delete('chat/messages/{message}/entity-links/{link}', [ChatController::class, 'destroyEntityLink'])->name('chat.entity-links.destroy');

        // Customer inbox, operator side. Each action additionally requires
        // chat.manage via ChatConversationPolicy::operate().
        Route::get('chat/inbox', [ChatController::class, 'inbox'])->name('chat.inbox');
        Route::post('chat/inbox/{conversation}/assign', [ChatController::class, 'assignOperator'])->name('chat.inbox.assign');
        Route::post('chat/inbox/{conversation}/transfer', [ChatController::class, 'transferConversation'])->name('chat.inbox.transfer');
        Route::post('chat/inbox/{conversation}/close', [ChatController::class, 'closeConversation'])->name('chat.inbox.close');
        Route::post('chat/inbox/{conversation}/convert', [ChatController::class, 'convertToTicket'])->name('chat.inbox.convert');

        Route::get('chat/unread', [ChatController::class, 'unread'])->name('chat.unread');
        Route::post('chat/presence', [ChatController::class, 'presenceHeartbeat'])->name('chat.presence');

        Route::delete('chat/messages/{message}', [ChatController::class, 'destroyMessage'])->name('chat.messages.destroy');

        /*
         | Message writes: 60/min per user (RateLimiter::for('chat')).
         |
         | `withoutMiddleware` matters as much as the throttle itself. This
         | file's group header carries `throttle:admin`, which caps every
         | non-GET admin request at 30/min — half the budget the chat needs,
         | and the limiter that would otherwise bite first, making
         | `throttle:chat` decorative. Both would apply; the tighter one wins.
         |
         | Deliberately NOT in this group: deleting a message (rare, and the
         | tighter admin cap is the right one for a destructive action) and the
         | typing/read/presence heartbeats (they must not spend the budget a
         | real message needs).
         */
        Route::middleware('throttle:chat')->withoutMiddleware('throttle:admin')->group(function () {
            Route::post('chat/conversations/{conversation}/messages', [ChatController::class, 'storeMessage'])->name('chat.messages.store');
            Route::put('chat/messages/{message}', [ChatController::class, 'updateMessage'])->name('chat.messages.update');
            Route::post('chat/messages/{message}/reactions', [ChatController::class, 'toggleReaction'])->name('chat.messages.reactions');
            Route::post('chat/messages/{message}/attachments', [ChatController::class, 'storeAttachment'])->name('chat.attachments.store');
        });

        // `signed` bounds how long a URL lasts; the policy check inside the
        // controller is what actually authorises the read.
        Route::get('chat/attachments/{attachment}', [ChatController::class, 'showAttachment'])
            ->middleware('signed')
            ->name('chat.attachments.show');
    });

    Route::get('chat/{chat}', [ChatController::class, 'show'])
        ->middleware('permission:chat.view')
        ->whereNumber('chat')
        ->name('chat.show');

    // Payment Gateway Settings
    Route::get('gateway-settings', [GatewayController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('gateway-settings.index');

    Route::get('gateway-settings/{gateway}/edit', [GatewayController::class, 'edit'])
        ->middleware('permission:settings.manage')
        ->name('gateway-settings.edit');

    Route::put('gateway-settings/{gateway}', [GatewayController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('gateway-settings.update');
});
