<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Huntress\HuntressClientMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshHuntressSecurityJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId) {}

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(HuntressClientMetricsService $metrics): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || empty($client->huntress_organization_id)) {
            return;
        }

        try {
            $metrics->refreshAndStore($client);
        } catch (\Throwable $e) {
            Log::error('Background Huntress security refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget('huntress_security.refresh_queued.'.$this->clientId);
    }
}
