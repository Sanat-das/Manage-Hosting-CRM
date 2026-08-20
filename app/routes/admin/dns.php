<?php

use App\Http\Controllers\Admin\DnsRecordController;
use App\Http\Controllers\Admin\DnsZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DNS management — admin web routes (Session 3B.2)
|--------------------------------------------------------------------------
| Wrapped in the standard admin group (web + auth + admin role) so these
| resources are NOT publicly reachable. Routes live under /admin with the
| admin. name prefix (sidebar contract: admin.dns-zones.*).
*/

Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dns-zones', [DnsZoneController::class, 'index'])
        ->middleware('permission:hosting.view')->name('dns-zones.index');
    Route::get('dns-zones/create', [DnsZoneController::class, 'create'])
        ->middleware('permission:hosting.manage')->name('dns-zones.create');
    Route::post('dns-zones', [DnsZoneController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('dns-zones.store');
    Route::get('dns-zones/{dnsZone}', [DnsZoneController::class, 'show'])
        ->middleware('permission:hosting.view')->name('dns-zones.show');
    Route::get('dns-zones/{dnsZone}/edit', [DnsZoneController::class, 'edit'])
        ->middleware('permission:hosting.manage')->name('dns-zones.edit');
    Route::put('dns-zones/{dnsZone}', [DnsZoneController::class, 'update'])
        ->middleware('permission:hosting.manage')->name('dns-zones.update');
    Route::delete('dns-zones/{dnsZone}', [DnsZoneController::class, 'destroy'])
        ->middleware('permission:hosting.manage')->name('dns-zones.destroy');

    Route::get('dns-zones/{dnsZone}/dns-records', [DnsRecordController::class, 'index'])
        ->middleware('permission:hosting.view')->name('dns-zones.records.index');
    Route::get('dns-zones/{dnsZone}/dns-records/create', [DnsRecordController::class, 'create'])
        ->middleware('permission:hosting.manage')->name('dns-zones.records.create');
    Route::post('dns-zones/{dnsZone}/dns-records', [DnsRecordController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('dns-zones.records.store');
    Route::get('dns-zones/{dnsZone}/dns-records/{dnsRecord}', [DnsRecordController::class, 'show'])
        ->middleware('permission:hosting.view')->name('dns-zones.records.show');
    Route::get('dns-zones/{dnsZone}/dns-records/{dnsRecord}/edit', [DnsRecordController::class, 'edit'])
        ->middleware('permission:hosting.manage')->name('dns-zones.records.edit');
    Route::put('dns-zones/{dnsZone}/dns-records/{dnsRecord}', [DnsRecordController::class, 'update'])
        ->middleware('permission:hosting.manage')->name('dns-zones.records.update');
    Route::delete('dns-zones/{dnsZone}/dns-records/{dnsRecord}', [DnsRecordController::class, 'destroy'])
        ->middleware('permission:hosting.manage')->name('dns-zones.records.destroy');
});
