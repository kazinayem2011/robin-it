<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the queue worker is actually running.
 *
 * Order confirmation, status update and welcome mail are all queued, and the
 * order itself succeeds whether or not the mail is ever delivered. That is the
 * right trade — a slow mail server must not fail a checkout — but it means a
 * dead worker is completely silent: customers simply stop receiving anything
 * and nothing in the application complains.
 *
 * This turns that silence into something the dashboard and a cron check can see.
 */
class QueueHealth
{
    /** A job older than this with nobody working on it means no worker is alive. */
    public const STALLED_AFTER_SECONDS = 300;

    /**
     * A job a worker reserved and never finished — it died mid-flight. Laravel
     * only returns those to the queue after retry_after, so give it room.
     */
    public const ABANDONED_AFTER_SECONDS = 1800;

    /**
     * @return array{
     *     driver: string, applicable: bool, healthy: bool, pending: int,
     *     oldest_pending_seconds: int|null, abandoned: int, failed: int,
     *     message: string
     * }
     */
    public static function check(): array
    {
        $driver = (string) config('queue.default');

        // sync runs the job inline, so there is no worker to be down.
        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            return self::report($driver, applicable: false, healthy: true, message: match (true) {
                $driver === 'sync' => 'Jobs run immediately; no worker is needed.',
                default => "Queue driver '{$driver}' is not monitored here.",
            });
        }

        $now = now()->getTimestamp();

        // Waiting, and nobody has picked it up.
        $waiting = DB::table('jobs')->whereNull('reserved_at');

        $pending = (int) $waiting->clone()->count();
        $oldestAvailableAt = $waiting->clone()->min('available_at');

        $oldestPendingSeconds = $oldestAvailableAt === null
            ? null
            : max(0, $now - (int) $oldestAvailableAt);

        // Reserved long ago and never finished: the worker died holding it.
        $abandoned = (int) DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $now - self::ABANDONED_AFTER_SECONDS)
            ->count();

        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $stalled = $oldestPendingSeconds !== null
            && $oldestPendingSeconds > self::STALLED_AFTER_SECONDS;

        return self::report(
            $driver,
            applicable: true,
            healthy: ! $stalled && $abandoned === 0,
            pending: $pending,
            oldestPendingSeconds: $oldestPendingSeconds,
            abandoned: $abandoned,
            failed: $failed,
            message: match (true) {
                $stalled => self::stalledMessage($pending, $oldestPendingSeconds),
                $abandoned > 0 => "{$abandoned} job(s) were picked up and never finished — a worker died mid-job.",
                $pending > 0 => "{$pending} job(s) queued and moving.",
                default => 'Queue is clear.',
            },
        );
    }

    private static function stalledMessage(int $pending, int $seconds): string
    {
        $waited = self::humanise($seconds);

        return "{$pending} job(s) have been waiting {$waited} with no worker picking them up. "
            .'Customer emails are not being delivered. Start the queue worker.';
    }

    public static function humanise(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }

        if ($seconds < 86400) {
            return floor($seconds / 3600).'h';
        }

        return floor($seconds / 86400).'d';
    }

    private static function report(
        string $driver,
        bool $applicable,
        bool $healthy,
        int $pending = 0,
        ?int $oldestPendingSeconds = null,
        int $abandoned = 0,
        int $failed = 0,
        string $message = '',
    ): array {
        return [
            'driver' => $driver,
            'applicable' => $applicable,
            'healthy' => $healthy,
            'pending' => $pending,
            'oldest_pending_seconds' => $oldestPendingSeconds,
            'abandoned' => $abandoned,
            'failed' => $failed,
            'message' => $message,
        ];
    }
}
