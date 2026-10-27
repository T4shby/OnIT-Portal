<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\MailDns\EntraMailDomainSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncClientEntraMailDomainsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $clientId)
    {
        $this->onQueue('default');
    }

    public function handle(EntraMailDomainSyncService $sync): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client === null || ! filled($client->entra_tenant_id)) {
            return;
        }

        try {
            $result = $sync->sync($client, importMissing: true, seedPrimaryIfEmpty: true);
            Log::info('Entra mail domain sync finished', [
                'client_id' => $this->clientId,
                'ok' => $result['ok'],
                'seeded_primary' => $result['seeded_primary'],
                'imported' => $result['imported'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Entra mail domain sync failed', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
