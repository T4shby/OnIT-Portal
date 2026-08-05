<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshDropsuiteBackupJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(DropsuiteClientMetricsService $metrics): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || empty($client->dropsuite_organization_id)) {
            Cache::forget('dropsuite_backup.refresh_queued.'.$this->clientId);
            Cache::forget('dropsuite_backup.refresh_started.'.$this->clientId);

            return;
        }

        $started = microtime(true);
        Cache::put('dropsuite_backup.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $metrics->refreshAndStore($client);

            Cache::put('dropsuite_backup.last_result.'.$client->id, [
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable $e) {
            Log::warning('Background Dropsuite backup refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            Cache::put('dropsuite_backup.last_result.'.$client->id, [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget('dropsuite_backup.refresh_queued.'.$client->id);
            Cache::forget('dropsuite_backup.refresh_started.'.$client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('dropsuite_backup.refresh_queued.'.$this->clientId);
        Cache::forget('dropsuite_backup.refresh_started.'.$this->clientId);
    }
}
