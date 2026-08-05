<?php

use App\Http\Controllers\Admin\KbController;
use App\Http\Controllers\Admin\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Support routes — Tickets + Knowledge Base (web)
|--------------------------------------------------------------------------
|
| Self-contained route group for the support domain. Loaded via
| bootstrap/app.php `withRouting(..., then:)`. Matches the convention used
| by routes/admin/ssl.php: web + auth + admin middleware, /admin prefix and
| an `admin.` name prefix.
|
| Route names are the sidebar contract in config/adminlte.php and the
| redirect targets used by the ticket/KB controllers:
|   admin.tickets.index, admin.tickets.create, admin.kb.index, admin.kb.create
|
| Permissions are seeded in InitialDataSeeder:
|   tickets.view / tickets.create / tickets.edit / tickets.assign
|   kb.view / kb.create / kb.edit / kb.delete
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Tickets — create must be registered before {ticket}.
        Route::get('tickets', [TicketController::class, 'index'])
            ->middleware('permission:tickets.view')
            ->name('tickets.index');

        Route::get('tickets/create', [TicketController::class, 'create'])
            ->middleware('permission:tickets.create')
            ->name('tickets.create');

        Route::post('tickets', [TicketController::class, 'store'])
            ->middleware('permission:tickets.create')
            ->name('tickets.store');

        Route::get('tickets/{ticket}', [TicketController::class, 'show'])
            ->middleware('permission:tickets.view')
            ->name('tickets.show');

        // Ticket actions — state changes are guarded by the TicketService.
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])
            ->middleware('permission:tickets.edit')
            ->name('tickets.reply');

        Route::post('tickets/{ticket}/note', [TicketController::class, 'storeNote'])
            ->middleware('permission:tickets.edit')
            ->name('tickets.note');

        Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])
            ->middleware('permission:tickets.edit')
            ->name('tickets.close');

        Route::post('tickets/{ticket}/reopen', [TicketController::class, 'reopen'])
            ->middleware('permission:tickets.edit')
            ->name('tickets.reopen');

        Route::put('tickets/{ticket}/assign', [TicketController::class, 'reassign'])
            ->middleware('permission:tickets.assign')
            ->name('tickets.assign');

        // Knowledge base — create before {article}.
        Route::get('kb', [KbController::class, 'index'])
            ->middleware('permission:kb.view')
            ->name('kb.index');

        Route::get('kb/create', [KbController::class, 'create'])
            ->middleware('permission:kb.create')
            ->name('kb.create');

        Route::post('kb', [KbController::class, 'store'])
            ->middleware('permission:kb.create')
            ->name('kb.store');

        Route::get('kb/{article}', [KbController::class, 'show'])
            ->middleware('permission:kb.view')
            ->name('kb.show');

        Route::get('kb/{article}/edit', [KbController::class, 'edit'])
            ->middleware('permission:kb.edit')
            ->name('kb.edit');

        Route::put('kb/{article}', [KbController::class, 'update'])
            ->middleware('permission:kb.edit')
            ->name('kb.update');

        Route::delete('kb/{article}', [KbController::class, 'destroy'])
            ->middleware('permission:kb.delete')
            ->name('kb.destroy');
    });