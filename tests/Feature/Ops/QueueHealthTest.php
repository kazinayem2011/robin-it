<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use App\Support\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Making a dead queue worker visible.
 *
 * Queued mail fails silently by design — checkout never waits for SMTP — so a
 * worker that has died looks exactly like a healthy one from inside the app.
 * A welcome email sat unsent for 14 hours before this existed.
 */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    /** Put a job on the database queue as though a producer had dispatched it. */
    private function queueJob(int $availableSecondsAgo, ?int $reservedSecondsAgo = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Mail\OrderConfirmationMail']),
            'attempts' => 0,
            'reserved_at' => $reservedSecondsAgo === null ? null : now()->getTimestamp() - $reservedSecondsAgo,
            'available_at' => now()->getTimestamp() - $availableSecondsAgo,
            'created_at' => now()->getTimestamp() - $availableSecondsAgo,
        ]);
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    public function test_an_empty_queue_is_healthy(): void
    {
        $this->useDatabaseQueue();

        $health = QueueHealth::check();

        $this->assertTrue($health['healthy']);
        $this->assertSame(0, $health['pending']);
        $this->assertSame('Queue is clear.', $health['message']);
    }

    public function test_a_job_that_was_just_queued_is_not_treated_as_stalled(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 10);

        $health = QueueHealth::check();

        $this->assertTrue($health['healthy'], 'a fresh job was reported as a dead worker');
        $this->assertSame(1, $health['pending']);
    }

    /** The real failure: nobody is picking jobs up at all. */
    public function test_a_job_nobody_picked_up_reports_a_dead_worker(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 14 * 3600);

        $health = QueueHealth::check();

        $this->assertFalse($health['healthy']);
        $this->assertSame(1, $health['pending']);
        $this->assertSame(14 * 3600, $health['oldest_pending_seconds']);
        $this->assertStringContainsString('14h', $health['message']);
        $this->assertStringContainsString('not being delivered', $health['message']);
    }

    public function test_a_job_reserved_and_never_finished_is_reported(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 7200, reservedSecondsAgo: 7200);

        $health = QueueHealth::check();

        $this->assertFalse($health['healthy']);
        $this->assertSame(1, $health['abandoned']);
        $this->assertStringContainsString('died mid-job', $health['message']);
        // It is being worked on, not waiting, so it is not counted as pending.
        $this->assertSame(0, $health['pending']);
    }

    /** A worker that picked something up moments ago is just busy. */
    public function test_a_recently_reserved_job_is_not_treated_as_abandoned(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 30, reservedSecondsAgo: 5);

        $health = QueueHealth::check();

        $this->assertTrue($health['healthy']);
        $this->assertSame(0, $health['abandoned']);
    }

    public function test_the_sync_driver_needs_no_worker(): void
    {
        config(['queue.default' => 'sync']);

        $health = QueueHealth::check();

        $this->assertTrue($health['healthy']);
        $this->assertFalse($health['applicable']);
        $this->assertStringContainsString('no worker is needed', $health['message']);
    }

    public function test_the_command_exits_non_zero_when_the_worker_is_down(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 3600);

        $this->artisan('queue:health')
            ->expectsOutputToContain('not being delivered')
            ->assertExitCode(1);
    }

    public function test_the_command_exits_zero_when_healthy(): void
    {
        $this->useDatabaseQueue();

        $this->artisan('queue:health')->assertExitCode(0);
    }

    public function test_quiet_mode_stays_silent_while_healthy(): void
    {
        $this->useDatabaseQueue();

        $this->artisan('queue:health --quiet-when-healthy')
            ->doesntExpectOutput('Queue is clear.')
            ->assertExitCode(0);
    }

    public function test_the_admin_dashboard_reports_the_queue_state(): void
    {
        $this->useDatabaseQueue();
        $this->queueJob(availableSecondsAgo: 6 * 3600);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/dashboard');
        $response->assertStatus(200);

        $health = $response->viewData('page')['props']['queueHealth'];

        $this->assertFalse($health['healthy'], 'the dashboard did not notice the dead worker');
        $this->assertStringContainsString('6h', $health['message']);
    }

    public function test_a_customer_never_sees_the_queue_state(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        $this->assertArrayNotHasKey('queueHealth', $response->viewData('page')['props']);
    }
}
