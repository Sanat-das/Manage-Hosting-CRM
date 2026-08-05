<?php

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff API routes (api.users.*, Sanctum)
|--------------------------------------------------------------------------
|
| Self-contained route file. Wired via bootstrap/app.php withRouting(then:)
| as a bare require, so it declares its own 'api' middleware group + 'api'
| prefix (routes/api.php is not edited). The 'api' group provides
| SubstituteBindings so implicit model binding works. Client accounts are
| excluded at the controller level.
|
| Endpoints:
|   GET    /api/users                 list (search / status filters, paginated)
|   POST   /api/users                 create staff account
|   GET    /api/users/{user}          detail (roles + permissions)
|   PUT    /api/users/{user}          update staff account
|   DELETE /api/users/{user}          delete staff account
|   POST   /api/users/{user}/toggle-status  activate / deactivate / suspend
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('api.users.index');
        Route::post('users', [UserController::class, 'store'])->name('api.users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('api.users.show');
        Route::put('users/{user}', [UserController::class, 'update'])->name('api.users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('api.users.destroy');
        Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('api.users.toggle-status');
    });
});
