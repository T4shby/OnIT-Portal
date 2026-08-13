<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Push SuperOps SCIM tokens + name mappings to Entra off the HTTP request.
 * Browser POST only validates and queues (avoids nginx 504 while Graph lags ~1–3 min).
 */
class ApplySuperOpsScimJob implements ShouldQueue, ShouldBeUnique, ShouldBeEncrypted
{
    use Queueable;

    public const IN_FLIGHT_KEY_PREFIX = 'scim_apply.in_flight.';

    public const LAST_RESULT_KEY_PREFIX = 'scim_apply.last_result.';

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function __construct(
        public int $clientId,
        public string $scimTenantUrl,
        public string $scimSecretToken,
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
            || ! filled($client->entra_superops_app_id)
        ) {
            $this->finish(false, 'Client is missing Entra tenant or SuperOps SCIM app ID.');

            return;
        }

        $started = microtime(true);

        try {
            $result = $graph->applySuperOpsScimCredentials(
                $client->entra_tenant_id,
                $client->entra_superops_app_id,
                $this->scimTenantUrl,
                $this->scimSecretToken,
            );
        } catch (\Throwable $e) {
            Log::warning('Background Apply SCIM failed', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            $this->finish(false, $e->getMessage(), [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return;
        }

        $nameMappingsOk = (bool) ($result['nameMappingsConfigured'] ?? false);

        $syncQueued = false;
        $syncBlockReason = null;
        if (! config('services.entra_sync.enabled')) {
            $syncBlockReason = 'Platform Entra sync is disabled (ENTRA_SYNC_ENABLED).';
        } elseif (! filled($client->entra_group_id)) {
            $syncBlockReason = 'Entra group ID is empty — save the security group Object ID, then re-Apply SCIM.';
        } else {
            if (! $client->entra_sync_enabled) {
                $client->update(['entra_sync_enabled' => true]);
                $client->refresh();
            }

            Cache::put('entra_sync.in_flight.'.$client->id, true, now()->addMinutes(15));
            SyncEntraClientJob::dispatchMarked($client->id, dryRun: false);
            $syncQueued = true;
        }

        $stepComplete = $nameMappingsOk && $syncQueued;

        $onboarding->updateChecklist($client, [
            'superops_scim_tokens' => true,
            'superops_scim_app' => true,
            'superops_scim_name_mappings' => $nameMappingsOk,
            'superops_scim_sync_queued' => $syncQueued,
            'superops_scim_provisioning' => $stepComplete,
        ]);

        $activityLog->log(
            'client.scim_credentials_applied',
            $client,
            properties: [
                'job_id' => $result['jobId'] ?? null,
                'started' => $result['started'] ?? false,
                'name_mappings' => $nameMappingsOk,
                'sync_queued' => $syncQueued,
                'step_complete' => $stepComplete,
                'details' => $result['details'] ?? [],
                'warnings' => $result['warnings'] ?? [],
                'scim_host' => parse_url($this->scimTenantUrl, PHP_URL_HOST),
                'background' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            clientId: $client->id,
        );

        $message = 'SuperOps SCIM credentials written to Entra';
        if ($nameMappingsOk) {
            $message .= ', SuperOps name mappings set (familyName ← extensionAttribute1)';
        } else {
            $message .= ', name mappings NOT set';
        }
        $message .= ', provisioning start requested.';
        if (! empty($result['details']) && is_array($result['details'])) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 6));
        }
        if ($syncQueued) {
            $message .= ' Portal Sync is running in the background.';
        } else {
            $message .= ' Portal Sync was not queued'.($syncBlockReason ? ' ('.$syncBlockReason.')' : '').'.';
        }

        $blockers = array_values(array_filter([
            ! $nameMappingsOk
                ? 'Step 07 stays Pending: name attribute mapping failed — re-Apply SCIM or set name.familyName Direct ← extensionAttribute1 in Entra Provisioning.'
                : null,
            ! $syncQueued
                ? 'Step 07 stays Pending: Sync not queued'.($syncBlockReason ? ' — '.$syncBlockReason : '.')
                : null,
        ]));
        $blockers = array_merge($blockers, array_slice($result['warnings'] ?? [], 0, 3));

        $this->finish($stepComplete, $message, [
            'step_complete' => $stepComplete,
            'name_mappings' => $nameMappingsOk,
            'sync_queued' => $syncQueued,
            'blockers' => $blockers,
            'warnings' => $result['warnings'] ?? [],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        if (! $stepComplete) {
            RepairSuperOpsScimExportJob::markQueued($client->id);
            RepairSuperOpsScimExportJob::dispatch($client->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(false, $exception?->getMessage() ?? 'Apply SCIM job failed.');
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
