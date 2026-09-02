<?php

use App\Http\Controllers\Api\HostingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosting module — Sanctum API routes (Session 3A.2)
|--------------------------------------------------------------------------
|
| Self-contained route file. Wired via bootstrap/app.php withRouting(then:)
| as a bare require, so it declares its own 'api' middleware group + 'api'
| prefix (routes/api.php is not edited). The 'api' group provides
| SubstituteBindings so implicit model binding works.
|
| Exposes /api/hosting REST CRUD mirroring the reference
| Modules\Hosting\Presentation\HostingRoutes, plus the lifecycle actions
| suspend / unsuspend / change-package. All endpoints require a valid
| Sanctum token (auth:sanctum).
|
*/

Route::middleware('api')->prefix('api')->group(function () {
    Route::middleware('auth:sanctum')->prefix('hosting')->name('api.hosting.')->group(function () {
        Route::get('/', [HostingController::class, 'index'])->name('index');
        Route::post('/', [HostingController::class, 'store'])->name('store');
        Route::get('{hostingAccount}', [HostingController::class, 'show'])->name('show');
        Route::put('{hostingAccount}', [HostingController::class, 'update'])->name('update');
        Route::delete('{hostingAccount}', [HostingController::class, 'destroy'])->name('destroy');

        Route::post('{hostingAccount}/suspend', [HostingController::class, 'suspend'])->name('suspend');
        Route::post('{hostingAccount}/unsuspend', [HostingController::class, 'unsuspend'])->name('unsuspend');
        Route::post('{hostingAccount}/change-package', [HostingController::class, 'changePackage'])->name('change-package');
    });
});
