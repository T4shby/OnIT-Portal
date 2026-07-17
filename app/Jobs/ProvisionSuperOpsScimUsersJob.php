<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs Entra SCIM provision-on-demand for specific users after a sync.
 * Kept off the main sync path so hourly schedule:run / SyncEntraClientJob can finish quickly.
 */
class ProvisionSuperOpsScimUsersJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /**
     * @param  list<string>  $userIds
     */
    public function __construct(
        public int $clientId,
        public array $userIds,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(EntraGroupSyncService $sync): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || $this->userIds === []) {
            return;
        }

        [$provisioned, $errors] = $sync->provisionSuperOpsScimUsers($client, $this->userIds);

        Log::info('Background SuperOps SCIM provision finished', [
            'client_id' => $client->id,
            'requested' => count($this->userIds),
            'provisioned' => $provisioned,
            'errors' => $errors,
        ]);
    }
}
