<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressClientMetricsService;
use App\Services\M365\M365DirectoryService;
use App\Services\M365\M365InsightsService;
use App\Services\Admin\IntegrationHealthService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep client dashboard caches filled so metrics exist before anyone opens the page.
 *
 * SuperOps (cold + past fresh window) is always queued on `high`.
 * Other integrations only when the jobs table is under capacity and they are cold/stale.
 */
class PrewarmClientDashboardsCommand extends Command
{
    protected $signature = 'portal:prewarm-client-dashboards';

    protected $description = 'Ensure active clients have SuperOps (and other) dashboard caches ready';

    /** Spare-capacity threshold for non-SuperOps prewarm work. */
    private const MAX_PENDING_BEFORE_OPTIONAL = 40;

    public function handle(
        SuperOpsClientMetricsService $superOps,
        M365DirectoryService $m365Directory,
        M365InsightsService $m365Insights,
        HuntressClientMetricsService $huntress,
        DropsuiteClientMetricsService $dropsuite,
    ): int {
        $pending = $this->pendingJobs();
        $queueDeep = $pending >= self::MAX_PENDING_BEFORE_OPTIONAL;

        $clientsProcessed = 0;
        $superOpsQueued = 0;
        $optionalQueued = 0;

        Client::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($clients) use (
                $superOps,
                $m365Directory,
                $m365Insights,
                $huntress,
                $dropsuite,
                $queueDeep,
                &$clientsProcessed,
                &$superOpsQueued,
                &$optionalQueued,
            ): void {
                foreach ($clients as $client) {
                    $clientsProcessed++;

                    // SuperOps always — cold or past fresh window. Never skip because other jobs are deep.
                    if (
                        ($superOps->needsColdPrewarm($client) || $superOps->needsBackgroundRefresh($client))
                        && $superOps->queueRefresh($client)
                    ) {
                        $superOpsQueued++;
                    }

                    if ($queueDeep) {
                        continue;
                    }

                    // Other integrations only when they need it (not every prewarm tick).
                    if ($m365Insights->needsBackgroundRefresh($client) && $m365Insights->queueRefresh($client)) {
                        $optionalQueued++;
                    }
                    if ($huntress->needsBackgroundRefresh($client) && $huntress->queueRefresh($client)) {
                        $optionalQueued++;
                    }
                    if ($dropsuite->needsBackgroundRefresh($client) && $dropsuite->queueRefresh($client)) {
                        $optionalQueued++;
                    }
                    if ($m365Directory->needsBackgroundRefresh($client) && $m365Directory->queueRefresh($client)) {
                        $optionalQueued++;
                    }
                }
            });

        $this->info(
            "Dashboard prewarm: {$superOpsQueued} SuperOps, {$optionalQueued} other, "
            ."across {$clientsProcessed} active client(s)"
            .($queueDeep ? ' (queue deep — SuperOps only).' : '.')
        );

        if ($queueDeep) {
            $this->warn("Optional prewarm skipped: {$pending} jobs already queued (max ".self::MAX_PENDING_BEFORE_OPTIONAL.').');
        }

        // Heartbeat for Integration Health pipeline panel (technicians).
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'superops_queued' => $superOpsQueued,
            'optional_queued' => $optionalQueued,
            'clients' => $clientsProcessed,
            'queue_deep' => $queueDeep,
            'pending_before' => $pending,
        ], now()->addDay());

        return self::SUCCESS;
    }

    private function pendingJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->count();
    }
}
