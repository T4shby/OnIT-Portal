<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\M365\M365InsightsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshM365InsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(M365InsightsService $insights): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || ! filled($client->entra_tenant_id)) {
            Cache::forget('m365_insights.refresh_queued.'.$this->clientId);
            Cache::forget('m365_insights.refresh_started.'.$this->clientId);

            return;
        }

        $started = microtime(true);
        Cache::put('m365_insights.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $insights->refreshAndStore($client);

            Cache::put('m365_insights.last_result.'.$client->id, [
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable $e) {
            Log::error('Background Microsoft 365 insights refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            Cache::put('m365_insights.last_result.'.$client->id, [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget('m365_insights.refresh_queued.'.$client->id);
            Cache::forget('m365_insights.refresh_started.'.$client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('m365_insights.refresh_queued.'.$this->clientId);
        Cache::forget('m365_insights.refresh_started.'.$this->clientId);
    }
}
