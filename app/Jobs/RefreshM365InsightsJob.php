<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\M365\M365InsightsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshM365InsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(M365InsightsService $insights): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || ! filled($client->entra_tenant_id)) {
            return;
        }

        try {
            $insights->refreshAndStore($client);
        } catch (\Throwable $e) {
            Log::error('Background Microsoft 365 insights refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
