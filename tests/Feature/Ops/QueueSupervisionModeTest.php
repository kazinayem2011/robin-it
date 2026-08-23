<?php

namespace Tests\Feature\Ops;

use App\Support\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * How the worker is supervised.
 *
 * A VPS runs a long-lived daemon under supervisor. Shared hosting kills those,
 * so there the scheduler drains the queue on each cron tick. Running both at
 * once would put two workers on the same jobs, so the modes are exclusive and
 * chosen by config.
 */
class QueueSupervisionModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What the operator actually sees.
     *
     * The schedule is built once while the console kernel boots, so changing
     * config inside a running test cannot re-register it. Asking a real
     * `schedule:list` in a subprocess is the only way to check the decision the
     * deployed app will make, and it cannot be fooled by test-only wiring.
     */
    private function scheduleListing(bool $viaScheduler): string
    {
        $result = Process::path(base_path())
            ->env([
                'QUEUE_RUN_VIA_SCHEDULER' => $viaScheduler ? 'true' : 'false',
                'QUEUE_SCHEDULER_MAX_SECONDS' => '55',
            ])
            ->run('php artisan schedule:list --no-ansi');

        $this->assertTrue($result->successful(), 'schedule:list failed: '.$result->errorOutput());

        return $result->output();
    }

    private function hasScheduledQueueWorker(bool $viaScheduler = true): bool
    {
        return str_contains($this->scheduleListing($viaScheduler), 'queue:work');
    }

    public function test_a_daemon_host_does_not_also_drain_from_cron(): void
    {
        $this->assertFalse(
            $this->hasScheduledQueueWorker(viaScheduler: false),
            'the scheduler would compete with the supervisor daemon for jobs'
        );
    }

    public function test_shared_hosting_drains_the_queue_from_cron(): void
    {
        $this->assertTrue(
            $this->hasScheduledQueueWorker(viaScheduler: true),
            'shared hosting has no daemon, so nothing would ever send mail'
        );
    }

    /** A drain that outlives its cron interval would stack ticks on top of each other. */
    public function test_the_drain_is_bounded_and_exits_when_the_queue_is_empty(): void
    {
        $listing = $this->scheduleListing(viaScheduler: true);

        $this->assertStringContainsString('--stop-when-empty', $listing);
        $this->assertStringContainsString('--max-time=55', $listing);
        $this->assertStringContainsString('--tries=3', $listing);
    }

    public function test_the_health_check_runs_in_both_modes(): void
    {
        foreach ([true, false] as $viaScheduler) {
            $this->assertStringContainsString(
                'queue:health',
                $this->scheduleListing($viaScheduler),
                'a dead worker would go unnoticed'
            );
        }
    }

    /**
     * On a host where cron only runs every 15 minutes, a job waiting 6 minutes
     * is normal — not an outage. The threshold has to follow the interval or
     * the dashboard cries wolf on every tick.
     */
    public function test_the_stall_threshold_follows_the_cron_interval(): void
    {
        config(['queue.default' => 'database', 'queue.health.stalled_after' => 1200]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Mail\OrderConfirmationMail']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() - 360,
            'created_at' => now()->getTimestamp() - 360,
        ]);

        $this->assertTrue(
            QueueHealth::check()['healthy'],
            'a 6 minute wait was called an outage on a 15 minute cron'
        );

        // The same job is an outage once the host is expected to be quicker.
        config(['queue.health.stalled_after' => 300]);
        $this->assertFalse(QueueHealth::check()['healthy']);
    }

    public function test_the_thresholds_cannot_be_set_absurdly_low(): void
    {
        config(['queue.health.stalled_after' => 1, 'queue.health.abandoned_after' => 1]);

        // A floor keeps a typo from reporting every healthy queue as broken.
        $this->assertSame(60, QueueHealth::stalledAfterSeconds());
        $this->assertSame(300, QueueHealth::abandonedAfterSeconds());
    }
}
