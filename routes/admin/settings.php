<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Settings, Analytics, Reports, Activity Log routes
|--------------------------------------------------------------------------
| Self-contained route file — wired in bootstrap/app.php via withRouting(then:).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])
        ->middleware('permission:settings.view')
        ->name('settings.index');

    Route::post('settings', [SettingsController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('settings.update');

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('analytics.index');

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('revenue', [ReportsController::class, 'revenue'])
            ->middleware('permission:reports.view')
            ->name('revenue');

        Route::get('customers', [ReportsController::class, 'customers'])
            ->middleware('permission:reports.view')
            ->name('customers');

        Route::get('tickets', [ReportsController::class, 'tickets'])
            ->middleware('permission:reports.view')
            ->name('tickets');

        Route::get('domains', [ReportsController::class, 'domains'])
            ->middleware('permission:reports.view')
            ->name('domains');

        Route::get('hosting', [ReportsController::class, 'hosting'])
            ->middleware('permission:reports.view')
            ->name('hosting');

        Route::get('sales', [ReportsController::class, 'sales'])
            ->middleware('permission:reports.view')
            ->name('sales');

        Route::get('export', [ReportsController::class, 'export'])
            ->middleware('permission:reports.export')
            ->name('export');
    });

    // Activity Log
    Route::get('activity-log', [ActivityLogController::class, 'index'])
        ->middleware('permission:activity.view')
        ->name('activity-log.index');
});
