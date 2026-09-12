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
        Route::post('chat/conversations/{conversation}/messages', [ChatController::class, 'storeMessage'])->name('chat.messages.store');
        Route::get('chat/conversations/{conversation}/threads/{parent}', [ChatController::class, 'fetchThread'])->name('chat.threads.show');
        Route::post('chat/conversations/{conversation}/typing', [ChatController::class, 'typingHeartbeat'])->name('chat.typing');
        Route::post('chat/conversations/{conversation}/read', [ChatController::class, 'markRead'])->name('chat.read');

        Route::get('chat/unread', [ChatController::class, 'unread'])->name('chat.unread');
        Route::post('chat/presence', [ChatController::class, 'presenceHeartbeat'])->name('chat.presence');

        Route::put('chat/messages/{message}', [ChatController::class, 'updateMessage'])->name('chat.messages.update');
        Route::delete('chat/messages/{message}', [ChatController::class, 'destroyMessage'])->name('chat.messages.destroy');
        Route::post('chat/messages/{message}/reactions', [ChatController::class, 'toggleReaction'])->name('chat.messages.reactions');
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
