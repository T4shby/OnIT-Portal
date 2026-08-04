<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressClientMetricsService;
use App\Services\M365\M365DirectoryService;
use App\Services\M365\M365InsightsService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep client dashboard caches filled so metrics exist before anyone opens the page.
 *
 * Cold SuperOps caches are always queued (high queue). Optional warm refreshes and
 * other integrations only when the jobs table is under capacity — never thrash Entra work.
 */
class PrewarmClientDashboardsCommand extends Command
{
    protected $signature = 'portal:prewarm-client-dashboards';

    protected $description = 'Ensure active clients have SuperOps (and other) dashboard caches ready';

    /** Spare-capacity threshold for optional (non-cold) prewarm work. */
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
        $coldQueued = 0;
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
                &$coldQueued,
                &$optionalQueued,
            ): void {
                foreach ($clients as $client) {
                    $clientsProcessed++;

                    // 1) Always fill missing SuperOps snapshots first.
                    if ($superOps->needsColdPrewarm($client) && $superOps->queueRefresh($client)) {
                        $coldQueued++;
                    }

                    if ($queueDeep) {
                        continue;
                    }

                    // 2) Refresh existing SuperOps data past the fresh window when spare capacity.
                    if ($superOps->needsBackgroundRefresh($client) && $superOps->queueRefresh($client)) {
                        $optionalQueued++;
                    }

                    $optionalQueued += (int) $m365Insights->queueRefresh($client);
                    $optionalQueued += (int) $huntress->queueRefresh($client);
                    $optionalQueued += (int) $dropsuite->queueRefresh($client);

                    if ($m365Directory->isAvailableForClient($client)) {
                        $optionalQueued += (int) $m365Directory->queueRefresh($client);
                    }
                }
            });

        $this->info(
            "Dashboard prewarm: {$coldQueued} cold SuperOps, {$optionalQueued} optional, "
            ."across {$clientsProcessed} active client(s)"
            .($queueDeep ? ' (queue deep — cold SuperOps only).' : '.')
        );

        if ($queueDeep) {
            $this->warn("Optional prewarm skipped: {$pending} jobs already queued (max ".self::MAX_PENDING_BEFORE_OPTIONAL.').');
        }

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
