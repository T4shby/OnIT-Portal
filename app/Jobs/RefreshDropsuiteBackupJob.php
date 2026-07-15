<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshDropsuiteBackupJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(DropsuiteClientMetricsService $metrics): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || empty($client->dropsuite_organization_id)) {
            return;
        }

        try {
            $metrics->refreshAndStore($client);
        } catch (\Throwable $e) {
            Log::warning('Background Dropsuite backup refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
