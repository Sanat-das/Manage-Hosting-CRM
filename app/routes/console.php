<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:recurring')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('domains:expiry-check --days=30')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('hosting:usage-sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('app:cleanup --days=90')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('ssl:check-expiry --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reports:send-scheduled --days=7')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('domains:sync-pricing')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();
