<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs Entra SCIM provision-on-demand for pending users after a sync.
 * User IDs are accumulated in cache so overlapping syncs merge instead of dropping.
 */
class ProvisionSuperOpsScimUsersJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(EntraGroupSyncService $sync): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $pendingKey = 'entra_scim_provision_pending.'.$client->id;
        $userIds = array_values(array_unique(Cache::pull($pendingKey, [])));

        if ($userIds === []) {
            return;
        }

        [$provisioned, $errors] = $sync->provisionSuperOpsScimUsers($client, $userIds);

        Log::info('Background SuperOps SCIM provision finished', [
            'client_id' => $client->id,
            'requested' => count($userIds),
            'provisioned' => $provisioned,
            'errors' => $errors,
        ]);
    }
}
