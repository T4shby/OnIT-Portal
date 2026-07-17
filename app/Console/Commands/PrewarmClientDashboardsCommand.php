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

class PrewarmClientDashboardsCommand extends Command
{
    protected $signature = 'portal:prewarm-client-dashboards';

    protected $description = 'Queue dashboard cache refreshes for active clients';

    public function handle(
        SuperOpsClientMetricsService $superOps,
        M365DirectoryService $m365Directory,
        M365InsightsService $m365Insights,
        HuntressClientMetricsService $huntress,
        DropsuiteClientMetricsService $dropsuite,
    ): int {
        $maxPending = 40;

        if (Schema::hasTable('jobs')) {
            $pending = (int) DB::table('jobs')->count();

            if ($pending >= $maxPending) {
                $this->warn("Skipping prewarm: {$pending} jobs already queued (max {$maxPending}).");

                return self::SUCCESS;
            }
        }

        $clientsProcessed = 0;
        $jobsQueued = 0;

        Client::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($clients) use (
                $superOps,
                $m365Directory,
                $m365Insights,
                $huntress,
                $dropsuite,
                &$clientsProcessed,
                &$jobsQueued,
            ): void {
                foreach ($clients as $client) {
                    $clientsProcessed++;

                    $jobsQueued += (int) $superOps->queueRefresh($client);
                    $jobsQueued += (int) $m365Insights->queueRefresh($client);
                    $jobsQueued += (int) $huntress->queueRefresh($client);
                    $jobsQueued += (int) $dropsuite->queueRefresh($client);

                    if ($m365Directory->isAvailableForClient($client)) {
                        $jobsQueued += (int) $m365Directory->queueRefresh($client);
                    }
                }
            });

        $this->info("Queued {$jobsQueued} dashboard refresh job(s) across {$clientsProcessed} active client(s).");

        return self::SUCCESS;
    }
}
