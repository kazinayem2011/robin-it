<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Watch the queue worker.
 *
 * Queued mail fails silently by design — an order is never held up waiting for
 * SMTP — so a worker that has died produces no error anywhere, just customers
 * who stop receiving confirmations. This writes a warning to the log the moment
 * jobs stop moving, and the admin dashboard shows the same state.
 *
 * Needs the scheduler itself to be running:
 *   * * * * * cd /var/www/robin-it && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('queue:health --quiet-when-healthy')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Prune the jobs tables so they do not grow without bound. Failed jobs are kept
 * for a week, which is long enough to notice and retry one.
 */
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
