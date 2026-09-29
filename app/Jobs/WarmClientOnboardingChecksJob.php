<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fills the caches the Edit Client onboarding checklist reads (`scim.health.*`
 * from Graph, `superops.requester_count.*` from up to 25 SuperOps pages) off
 * the HTTP request. The page itself only reads those caches, so a cold cache
 * can never put live 30-60s external calls on an admin page load.
 */
class WarmClientOnboardingChecksJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(ClientOnboardingService $onboarding, SuperOpsUserSyncService $superOpsUsers): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client === null) {
            return;
        }

        try {
            $onboarding->scimExportHealth($client);
        } catch (\Throwable $e) {
            Log::warning('Onboarding SCIM health warm failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        // countClientRequesters() catches its own API errors.
        $superOpsUsers->countClientRequesters($client);
    }
}
