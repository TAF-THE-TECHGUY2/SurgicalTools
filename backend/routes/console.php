<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Run the daily inventory checks. In production register the system cron:
|   * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('surgical:check-expiring-stock')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('surgical:check-low-stock')
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->onOneServer();
