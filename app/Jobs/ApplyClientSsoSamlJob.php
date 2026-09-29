<?php

namespace App\Jobs;

use App\Jobs\Concerns\ClaimsInFlightSlot;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Wires SuperOps Client SSO SAML config into Entra off the HTTP request.
 * MicrosoftGraphClient::applyClientSsoSamlConfiguration() waits on
 * application/service-principal readiness the same way applySuperOpsScimCredentials()
 * does, so it carries the same nginx-504 risk ApplySuperOpsScimJob was queued for.
 *
 * The resulting Login URL / certificate are cached under client_sso_idp.{id} for the
 * Blade view (_client-sso-apply-form.blade.php) to read - same "poll a cache key,
 * refresh to check" pattern used for scim_apply.in_flight / scim_apply.last_result.
 */
class ApplyClientSsoSamlJob implements ShouldQueue, ShouldBeUnique
{
    use ClaimsInFlightSlot;
    use Queueable;

    public const IN_FLIGHT_KEY_PREFIX = 'client_sso_apply.in_flight.';

    public const LAST_RESULT_KEY_PREFIX = 'client_sso_apply.last_result.';

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function __construct(
        public int $clientId,
        public string $entityId,
        public string $consumerServiceUrl,
    ) {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public static function markQueued(int $clientId): void
    {
        Cache::put(self::IN_FLIGHT_KEY_PREFIX.$clientId, true, now()->addMinutes(15));
        Cache::forget(self::LAST_RESULT_KEY_PREFIX.$clientId);
    }

    public function handle(
        MicrosoftGraphClient $graph,
        ClientOnboardingService $onboarding,
        ActivityLogService $activityLog,
    ): void {
        $client = Client::query()->find($this->clientId);

        if ($client === null
            || ! filled($client->entra_tenant_id)
            || ! filled($client->entra_superops_sso_app_id)
        ) {
            $this->finish(false, 'Client is missing an Entra tenant ID or Client SSO Application ID.');

            return;
        }

        $started = microtime(true);

        try {
            $result = $graph->applyClientSsoSamlConfiguration(
                $client->entra_tenant_id,
                $client->entra_superops_sso_app_id,
                $this->entityId,
                $this->consumerServiceUrl,
            );
        } catch (\Throwable $e) {
            Log::warning('Background Apply Client SSO failed', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            $this->finish(false, $e->getMessage(), [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return;
        }

        Cache::put('client_sso_idp.'.$client->id, [
            'loginUrl' => $result['loginUrl'],
            'certificateBase64' => $result['certificateBase64'],
            'entityId' => $this->entityId,
            'consumerServiceUrl' => $this->consumerServiceUrl,
            'configuredAt' => now()->toIso8601String(),
        ], now()->addDays(14));

        $onboarding->updateChecklist($client, [
            'superops_client_sso_configured' => true,
        ]);

        $activityLog->log(
            'client.client_sso_saml_configured',
            $client,
            properties: [
                'login_host' => parse_url($result['loginUrl'], PHP_URL_HOST),
                'details' => $result['details'],
                'warnings' => $result['warnings'],
                'background' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            clientId: $client->id,
        );

        $message = 'Client SSO SAML configured in Entra. Copy Login URL + certificate below into SuperOps Client SSO and Save.';
        if ($result['details'] !== []) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 5));
        }

        $this->finish(true, $message, [
            'warnings' => $result['warnings'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(false, $exception?->getMessage() ?? 'Apply Client SSO job failed.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(bool $success, string $message, array $extra = []): void
    {
        Cache::forget(self::IN_FLIGHT_KEY_PREFIX.$this->clientId);
        Cache::put(self::LAST_RESULT_KEY_PREFIX.$this->clientId, array_merge([
            'success' => $success,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], $extra), now()->addDay());
    }
}
