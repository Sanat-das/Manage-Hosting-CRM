<?php

use App\Http\Controllers\Api\KbController as ApiKbController;
use App\Http\Controllers\Api\TicketController as ApiTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Support API routes (Sanctum)
|--------------------------------------------------------------------------
|
| Tickets + Knowledge Base mirroring the reference /api/tickets and
| /api/kb endpoints. Self-contained: wired via bootstrap/app.php
| withRouting(then:) as a bare require, so it declares its own 'api'
| middleware group + 'api' prefix (routes/api.php is not edited).
|
| NB: static routes (tickets/stats, kb/categories, kb/popular) MUST be
| declared before the model-bound {ticket} / {article} routes.
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('tickets')->group(function () {
        Route::get('/', [ApiTicketController::class, 'index']);
        Route::post('/', [ApiTicketController::class, 'store']);
        Route::get('stats', [ApiTicketController::class, 'stats']);
        Route::get('{ticket}', [ApiTicketController::class, 'show']);
        Route::post('{ticket}/reply', [ApiTicketController::class, 'reply']);
        Route::post('{ticket}/close', [ApiTicketController::class, 'close']);
        Route::post('{ticket}/reopen', [ApiTicketController::class, 'reopen']);
    });

    // Knowledge base — Sanctum-protected article browsing + category management
    Route::middleware('auth:sanctum')->prefix('kb')->group(function () {
        Route::get('/', [ApiKbController::class, 'index']);
        Route::post('/', [ApiKbController::class, 'store']);
        Route::get('categories', [ApiKbController::class, 'categories']);
        Route::post('categories', [ApiKbController::class, 'storeCategory']);
        Route::delete('categories/{category}', [ApiKbController::class, 'deleteCategory']);
        Route::get('popular', [ApiKbController::class, 'popular']);
        Route::get('{article}', [ApiKbController::class, 'show']);
        Route::put('{article}', [ApiKbController::class, 'update']);
        Route::delete('{article}', [ApiKbController::class, 'destroy']);
    });
});
