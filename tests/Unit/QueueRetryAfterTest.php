<?php

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * The database queue re-reserves any job whose row has been reserved for longer
 * than retry_after. If that is shorter than a job's $timeout, the next minute's
 * worker runs the same job again while the first is still going (a tries=1 job
 * is then failed mid-run). Laravel's default is 90s; our longest jobs run 600s.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_database_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $timeouts[$class] = (int) ($reflection->getDefaultProperties()['timeout'] ?? 0);
        }

        $this->assertNotEmpty($timeouts);
        $this->assertContainsOnly('int', $timeouts);
        $this->assertNotContains(0, $timeouts, 'Every queued job must declare an explicit $timeout.');

        arsort($timeouts);
        $longest = array_key_first($timeouts);

        $this->assertGreaterThan(
            $timeouts[$longest],
            $retryAfter,
            "queue.connections.database.retry_after ({$retryAfter}s) must exceed {$longest}::\$timeout ({$timeouts[$longest]}s).",
        );
    }
}
