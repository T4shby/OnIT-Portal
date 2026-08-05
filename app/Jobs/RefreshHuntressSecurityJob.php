<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Huntress\HuntressClientMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshHuntressSecurityJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(HuntressClientMetricsService $metrics): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || empty($client->huntress_organization_id)) {
            Cache::forget('huntress_security.refresh_queued.'.$this->clientId);
            Cache::forget('huntress_security.refresh_started.'.$this->clientId);

            return;
        }

        $started = microtime(true);
        Cache::put('huntress_security.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $metrics->refreshAndStore($client);

            Cache::put('huntress_security.last_result.'.$client->id, [
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable $e) {
            Log::error('Background Huntress security refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            Cache::put('huntress_security.last_result.'.$client->id, [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget('huntress_security.refresh_queued.'.$client->id);
            Cache::forget('huntress_security.refresh_started.'.$client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('huntress_security.refresh_queued.'.$this->clientId);
        Cache::forget('huntress_security.refresh_started.'.$this->clientId);
    }
}
