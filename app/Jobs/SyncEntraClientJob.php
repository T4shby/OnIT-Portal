<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs after the HTTP response is sent (dispatch()->afterResponse()) so nginx does not 504.
 */
class SyncEntraClientJob
{
    use Dispatchable, Queueable;

    public function __construct(public int $clientId) {}

    public function handle(EntraGroupSyncService $sync, ActivityLogService $activityLog): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $result = $sync->syncClient($client, dryRun: false);

        Cache::put(
            'entra_sync.last_result.'.$client->id,
            [
                'summary' => $result->summary(),
                'failed' => $result->failed(),
                'warnings' => $result->hasWarnings(),
                'errors' => array_slice($result->errors, 0, 5),
                'finished_at' => now()->toIso8601String(),
            ],
            now()->addDay(),
        );

        $activityLog->log(
            'client.entra_synced',
            $client,
            properties: [
                'created' => $result->created,
                'updated' => $result->updated,
                'deactivated' => $result->deactivated,
                'skipped' => $result->skipped,
                'warnings' => count($result->errors),
                'async' => true,
            ],
            clientId: $client->id,
        );

        if ($result->failed()) {
            Log::error('Background Entra sync failed', [
                'client_id' => $client->id,
                'errors' => $result->errors,
            ]);
        }
    }
}
