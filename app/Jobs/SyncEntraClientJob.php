<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs Entra group sync for one client via the database queue (not inline on the HTTP request).
 */
class SyncEntraClientJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public int $clientId,
        public bool $dryRun = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->clientId.($this->dryRun ? ':dry' : '');
    }

    public function handle(EntraGroupSyncService $sync, ActivityLogService $activityLog): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            Cache::forget('entra_sync.refresh_queued.'.$this->clientId);
            Cache::forget('entra_sync.refresh_started.'.$this->clientId);

            return;
        }

        $started = microtime(true);
        Cache::put('entra_sync.refresh_queued.'.$client->id, true, now()->addMinutes(30));
        Cache::put('entra_sync.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(30));

        try {
            $result = $sync->syncClient($client, dryRun: $this->dryRun);

            Cache::put(
                'entra_sync.last_result.'.$client->id,
                [
                    'success' => ! $result->failed(),
                    'summary' => $result->summary($this->dryRun),
                    'failed' => $result->failed(),
                    'warnings' => $result->hasWarnings(),
                    'errors' => array_slice($result->errors, 0, 5),
                    'error' => $result->failed() ? ($result->errors[0] ?? 'Entra sync failed') : null,
                    'dry_run' => $this->dryRun,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'finished_at' => now()->toIso8601String(),
                ],
                now()->addDay(),
            );

            $activityLog->log(
                $this->dryRun ? 'client.entra_sync_dry_run' : 'client.entra_synced',
                $client,
                properties: [
                    'created' => $result->created,
                    'updated' => $result->updated,
                    'deactivated' => $result->deactivated,
                    'skipped' => $result->skipped,
                    'warnings' => count($result->errors),
                    'async' => true,
                    'dry_run' => $this->dryRun,
                ],
                clientId: $client->id,
            );

            if ($result->failed()) {
                Log::error('Background Entra sync failed', [
                    'client_id' => $client->id,
                    'dry_run' => $this->dryRun,
                    'errors' => $result->errors,
                ]);
            }
        } finally {
            Cache::forget('entra_sync.refresh_queued.'.$client->id);
            Cache::forget('entra_sync.refresh_started.'.$client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('entra_sync.refresh_queued.'.$this->clientId);
        Cache::forget('entra_sync.refresh_started.'.$this->clientId);
    }
}
