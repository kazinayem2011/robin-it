<?php

namespace App\Console\Commands;

use App\Support\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Report whether the queue worker is alive.
 *
 * Exits non-zero when it is not, so cron, a monitoring probe or a deploy check
 * can act on it. Queued mail failing silently is the failure this exists to
 * make loud.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health
                            {--json : Output machine-readable JSON}
                            {--quiet-when-healthy : Print nothing unless something is wrong}';

    protected $description = 'Check that the queue worker is running and jobs are moving';

    public function handle(): int
    {
        $health = QueueHealth::check();

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $health['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        if ($health['healthy'] && $this->option('quiet-when-healthy')) {
            return self::SUCCESS;
        }

        if (! $health['healthy']) {
            // Goes to the log as well, so an unattended cron run leaves a trace.
            Log::warning('Queue health check failed: '.$health['message'], $health);

            $this->error($health['message']);
        } else {
            $this->info($health['message']);
        }

        if ($health['applicable']) {
            $this->newLine();
            $this->table(['Metric', 'Value'], [
                ['Driver', $health['driver']],
                ['Pending', $health['pending']],
                ['Oldest pending', $health['oldest_pending_seconds'] === null
                    ? '—'
                    : QueueHealth::humanise($health['oldest_pending_seconds'])],
                ['Abandoned', $health['abandoned']],
                ['Failed', $health['failed']],
            ]);

            if (! $health['healthy']) {
                $this->newLine();
                $this->line('  Start a worker with:  <comment>php artisan queue:work --tries=3</comment>');
                $this->line('  In production, run it under supervisor — see <comment>deploy/README.md</comment>.');
            }

            if ($health['failed'] > 0) {
                $this->newLine();
                $this->line("  {$health['failed']} job(s) have failed permanently. Inspect with "
                    .'<comment>php artisan queue:failed</comment>.');
            }
        }

        return $health['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
