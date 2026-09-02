<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin staff management routes (admin.users.*)
|--------------------------------------------------------------------------
|
| Self-contained route file for the staff module. The wiring task loads this
| file from routes/web.php alongside the existing admin group (same
| web/auth/admin middleware stack, same `admin` prefix, name prefix `admin.`).
|
| Permission gates match the InitialDataSeeder vocabulary:
|   users.view   -> list / view
|   users.create -> create / store
|   users.edit   -> edit / update / toggle-status / reset-password
|   users.delete -> destroy
|
| NB: `users/create` must be registered before `users/{user}` so the literal
| segment wins over the implicit model binding.
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('users.index');

    Route::get('users/create', [UserController::class, 'create'])
        ->middleware('permission:users.create')
        ->name('users.create');

    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:users.create')
        ->name('users.store');

    Route::get('users/{user}', [UserController::class, 'show'])
        ->middleware('permission:users.view')
        ->name('users.show');

    Route::get('users/{user}/edit', [UserController::class, 'edit'])
        ->middleware('permission:users.edit')
        ->name('users.edit');

    Route::put('users/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.edit')
        ->name('users.update');

    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->name('users.destroy');

    Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
        ->middleware('permission:users.edit')
        ->name('users.toggle-status');

    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware(['permission:users.edit', 'throttle:password-reset-email'])
        ->name('users.reset-password');

    Route::post('users/{user}/set-password', [UserController::class, 'setPassword'])
        ->middleware(['permission:users.edit', 'throttle:password-set'])
        ->name('users.set-password');
});
