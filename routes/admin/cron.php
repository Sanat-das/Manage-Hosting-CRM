<?php

use App\Http\Controllers\Admin\CronJobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Cron Jobs routes
|--------------------------------------------------------------------------
|
| Self-contained route file wired in bootstrap/app.php via withRouting(then:).
|
| Route names are the sidebar contract (config/adminlte.php menu):
|   - admin.cron.index
|   - admin.cron.toggle, admin.cron.run, admin.cron.pause
|
| Permission gates:
|   - cron.view   — read the task list, health banner and run history
|   - cron.manage — enable/disable a task, run one now, pause the scheduler
|
| The tasks themselves are declared in routes/console.php; nothing here
| defines a schedule. All state-changing routes are POST form submits (@csrf).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('cron', [CronJobController::class, 'index'])
        ->middleware('permission:cron.view')
        ->name('cron.index');

    Route::post('cron/toggle', [CronJobController::class, 'toggle'])
        ->middleware('permission:cron.manage')
        ->name('cron.toggle');

    Route::post('cron/update', [CronJobController::class, 'update'])
        ->middleware('permission:cron.manage')
        ->name('cron.update');

    Route::post('cron/run', [CronJobController::class, 'run'])
        ->middleware('permission:cron.manage')
        ->name('cron.run');

    Route::post('cron/pause', [CronJobController::class, 'pause'])
        ->middleware('permission:cron.manage')
        ->name('cron.pause');
});
