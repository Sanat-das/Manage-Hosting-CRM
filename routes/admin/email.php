<?php

use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Email routes
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Email Templates
    Route::get('email-templates', [EmailTemplateController::class, 'index'])
        ->middleware('permission:email.view')
        ->name('email-templates.index');

    Route::get('email-templates/create', [EmailTemplateController::class, 'create'])
        ->middleware('permission:email.manage')
        ->name('email-templates.create');

    Route::post('email-templates', [EmailTemplateController::class, 'store'])
        ->middleware('permission:email.manage')
        ->name('email-templates.store');

    Route::get('email-templates/{emailTemplate}', [EmailTemplateController::class, 'show'])
        ->middleware('permission:email.view')
        ->name('email-templates.show');

    Route::get('email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])
        ->middleware('permission:email.manage')
        ->name('email-templates.edit');

    Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])
        ->middleware('permission:email.manage')
        ->name('email-templates.update');

    Route::delete('email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])
        ->middleware('permission:email.manage')
        ->name('email-templates.destroy');

    // Email Logs
    Route::get('email-logs', [EmailLogController::class, 'index'])
        ->middleware('permission:email.view')
        ->name('email-logs.index');

    Route::get('email-logs/{emailLog}', [EmailLogController::class, 'show'])
        ->middleware('permission:email.view')
        ->name('email-logs.show');
});
