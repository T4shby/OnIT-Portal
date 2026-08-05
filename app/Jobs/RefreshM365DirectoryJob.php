<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\M365\M365DirectoryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes Microsoft 365 directory data via the database queue (not inline on the HTTP request).
 */
class RefreshM365DirectoryJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $clientId)
    {
        // Same high lane as SuperOps so directory does not sit behind Entra/SCIM.
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(M365DirectoryService $directory): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || ! $directory->isAvailableForClient($client)) {
            Cache::forget('m365_directory.refresh_queued.'.$this->clientId);
            Cache::forget('m365_directory.refresh_started.'.$this->clientId);

            return;
        }

        $lockKey = 'm365_directory.refresh.'.$client->id;
        $lock = Cache::lock($lockKey, (int) config('services.entra_sync.directory_refresh_lock_seconds', 600));

        if (! $lock->get()) {
            // Another worker already owns this client — clear "queued" so UI does not spin forever.
            Cache::forget('m365_directory.refresh_queued.'.$client->id);
            Cache::forget('m365_directory.refresh_started.'.$client->id);

            return;
        }

        $started = microtime(true);
        Cache::put('m365_directory.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $directory->buildAndStoreSnapshot($client);

            Cache::put('m365_directory.last_result.'.$client->id, [
                'success' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable $e) {
            Log::error('Background M365 directory refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            Cache::put('m365_directory.last_result.'.$client->id, [
                'success' => false,
                'error' => 'Directory refresh failed: '.$e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget('m365_directory.refresh_queued.'.$client->id);
            Cache::forget('m365_directory.refresh_started.'.$client->id);
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('m365_directory.refresh_queued.'.$this->clientId);
        Cache::forget('m365_directory.refresh_started.'.$this->clientId);
    }
}
