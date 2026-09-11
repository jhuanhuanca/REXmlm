<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:scan-alerts')->dailyAt('08:00');
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('invitations:expire')->daily();
Schedule::command('catalog:refresh-user-companies')->dailyAt('03:30');
Schedule::command('catalog:sync-stores')->everyFifteenMinutes()->withoutOverlapping(20);
Schedule::command('horizon:snapshot')->everyFiveMinutes();
