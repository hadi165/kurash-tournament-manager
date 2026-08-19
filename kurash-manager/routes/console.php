<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | A nightly backup is the floor, not the plan. Competition data changes in
 | bursts over a weekend and then not at all for weeks, so also run
 | `php artisan kurash:backup --label=before-session` by hand before each
 | session — that is the copy you will actually want.
 |
 | Needs one cron entry on the server:
 |   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('kurash:backup', ['--keep=30'])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

// Failed scoreboard pushes are retried rather than left in the table forever.
Schedule::command('queue:prune-failed', ['--hours=168'])->weekly();
