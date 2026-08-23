<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Everything below needs the scheduler itself to be running. One cron entry:
 *
 *   * * * * * cd /path/to/robin-it && php artisan schedule:run >> /dev/null 2>&1
 */

/*
 * Drain the queue on shared hosting.
 *
 * Shared hosts kill long-running processes, so `queue:work` as a daemon does
 * not survive there. Instead each cron tick works through whatever is waiting
 * and exits before the next tick starts.
 *
 * Off by default: on a VPS the worker runs under supervisor or systemd, and
 * both draining at once would have two workers competing for the same jobs.
 */
if (config('queue.run_via_scheduler')) {
    $maxSeconds = (int) config('queue.scheduler_max_seconds', 55);

    Schedule::command("queue:work --stop-when-empty --max-time={$maxSeconds} --tries=3 --backoff=10")
        ->everyMinute()
        // A tick that overruns must not stack up behind the next one. The lock
        // expires so a killed process cannot wedge the queue shut forever.
        ->withoutOverlapping(5);
}

/*
 * Watch the queue worker.
 *
 * Queued mail fails silently by design — an order is never held up waiting for
 * SMTP — so a worker that has died produces no error anywhere, just customers
 * who stop receiving confirmations. This writes a warning to the log the moment
 * jobs stop moving, and the admin dashboard shows the same state.
 */
Schedule::command('queue:health --quiet-when-healthy')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Prune the jobs tables so they do not grow without bound. Failed jobs are kept
 * for a week, which is long enough to notice and retry one.
 */
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
