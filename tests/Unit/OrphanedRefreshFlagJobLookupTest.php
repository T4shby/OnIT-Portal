<?php

namespace Tests\Unit;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Jobs\RefreshHuntressSecurityJob;
use App\Jobs\RefreshM365DirectoryJob;
use App\Jobs\RefreshM365InsightsJob;
use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressClientMetricsService;
use App\Services\M365\M365DirectoryService;
use App\Services\M365\M365InsightsService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Each feed clears its "refresh queued/started" flags when the jobs table has no
 * job for the client. The lookup matched `clientId";i:N;`, but real database-queue
 * payloads store the serialized command JSON-escaped (`clientId\";i:N;`), so a
 * pending or running job was never found and the flags were always cleared.
 * These tests push real jobs through the database queue connection.
 */
class OrphanedRefreshFlagJobLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string, string, class-string, string, bool}>
     */
    public static function feeds(): array
    {
        return [
            'superops' => [SuperOpsClientMetricsService::class, 'clearOrphanedRefreshFlags', RefreshSuperOpsDashboardJob::class, 'superops_dashboard', false],
            'huntress' => [HuntressClientMetricsService::class, 'clearOrphanedFeedFlags', RefreshHuntressSecurityJob::class, 'huntress_security', false],
            'dropsuite' => [DropsuiteClientMetricsService::class, 'clearOrphanedFeedFlags', RefreshDropsuiteBackupJob::class, 'dropsuite_backup', false],
            'm365 directory' => [M365DirectoryService::class, 'clearOrphanedRefreshFlags', RefreshM365DirectoryJob::class, 'm365_directory', true],
            'm365 insights' => [M365InsightsService::class, 'clearOrphanedRefreshFlags', RefreshM365InsightsJob::class, 'm365_insights', false],
        ];
    }

    #[DataProvider('feeds')]
    public function test_pending_job_keeps_flags_and_missing_job_clears_them(
        string $serviceClass,
        string $method,
        string $jobClass,
        string $prefix,
        bool $takesClient,
    ): void {
        // Low id + a second client with an overlapping id prefix (1 vs 12) so a
        // loose match would show up as a false "pending".
        $client = Client::factory()->create();
        Client::factory()->count(11)->create();

        $service = app($serviceClass);
        $clear = new ReflectionMethod($service, $method);
        $invoke = function () use ($clear, $service, $client, $takesClient, $prefix, $jobClass): void {
            $args = $takesClient ? [$client] : [$client->id];
            if ($clear->getNumberOfParameters() === 3) {
                $args = [$client->id, $prefix, class_basename($jobClass)];
            }
            $clear->invoke($service, ...$args);
        };

        Queue::connection('database')->push(new $jobClass($client->id));
        $this->assertSame(1, DB::table('jobs')->count());

        Cache::put($prefix.'.refresh_queued.'.$client->id, true, now()->addMinutes(15));
        Cache::put($prefix.'.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(15));

        $invoke();

        $this->assertTrue(Cache::has($prefix.'.refresh_queued.'.$client->id), 'A pending job was treated as orphaned.');
        $this->assertTrue(Cache::has($prefix.'.refresh_started.'.$client->id));

        DB::table('jobs')->delete();
        $invoke();

        $this->assertFalse(Cache::has($prefix.'.refresh_queued.'.$client->id), 'A genuinely orphaned flag was kept.');
        $this->assertFalse(Cache::has($prefix.'.refresh_started.'.$client->id));
    }
}
