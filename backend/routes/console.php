<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll Zoho Mail for inbound replies. Requires `php artisan schedule:work`
// (or a system cron entry running `php artisan schedule:run` every minute).
Schedule::command('mail:poll')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// MGM volume reconciliation — safety net for the PolicyPaymentObserver.
// Runs nightly for the current month. Reconciling the current month picks
// up any observer misses from the day; ops can reconcile prior months
// manually when needed via `php artisan mgm:reconcile-volumes --month=YYYY-MM`.
Schedule::command('mgm:reconcile-volumes')
    ->dailyAt('02:00')
    ->withoutOverlapping();
