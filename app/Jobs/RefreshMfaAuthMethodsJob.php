<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\M365\MfaAuthenticationMethodsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshMfaAuthMethodsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $clientId)
    {
        $this->onQueue('default');
    }

    public function handle(MfaAuthenticationMethodsService $methods): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client === null) {
            return;
        }

        try {
            $result = $methods->refresh($client);
            Log::info('MFA auth methods refreshed', [
                'client_id' => $this->clientId,
                'available' => $result['available'] ?? false,
                'users' => $result['totals']['users'] ?? 0,
                'sms_voice_risk' => $result['totals']['sms_voice_risk'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MFA auth methods refresh job failed', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
