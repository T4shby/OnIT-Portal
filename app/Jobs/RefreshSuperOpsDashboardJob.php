<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshSuperOpsDashboardJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId)
    {
        // Ahead of Entra/SCIM (default queue) so dashboards stay warm under backlog.
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(SuperOpsClientMetricsService $metrics): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || empty($client->superops_account_id)) {
            Cache::forget('superops_dashboard.refresh_queued.'.$this->clientId);
            Cache::forget('superops_dashboard.refresh_started.'.$this->clientId);

            return;
        }

        $started = microtime(true);
        Cache::put('superops_dashboard.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $metrics->refreshAndStore($client);

            Cache::put('superops_dashboard.last_result.'.$client->id, [
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable $e) {
            Log::error('Background SuperOps dashboard refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            Cache::put('superops_dashboard.last_result.'.$client->id, [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget('superops_dashboard.refresh_queued.'.$client->id);
            Cache::forget('superops_dashboard.refresh_started.'.$client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('superops_dashboard.refresh_queued.'.$this->clientId);
        Cache::forget('superops_dashboard.refresh_started.'.$this->clientId);
    }
}
