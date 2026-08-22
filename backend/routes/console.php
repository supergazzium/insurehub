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

// C-16 — daily policy state-machine transitions per B1 §7:
//   1. issued → active   (effective_date reached)
//   2. active → expired  (expiry_date passed)
//   3. draft retention   (>30 day drafts soft-deleted)
// 00:15 to buffer past midnight for clock skew on date comparisons.
Schedule::command('policies:transition-daily')
    ->dailyAt('00:15')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();

// C-17 — daily shim-soak report. Sends a JSON line to the app log at
// 00:30 so any monitoring/alerting can pick up "risk shim silent for
// N days" as the ops signal for scheduling C-18's drop-columns
// migration. Read-only; safe to run alongside the daily transitions.
Schedule::command('policies:shim-report --json')
    ->dailyAt('00:30')
    ->timezone('Asia/Bangkok')
    ->appendOutputTo(storage_path('logs/shim-report.log'));
