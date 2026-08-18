<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\SuperOpsScimRepairService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Retry Entra SCIM export (Sync 1) without re-pasting SuperOps secret - for half-failed Apply SCIM.
 */
class RepairSuperOpsScimExportJob implements ShouldQueue, ShouldBeUnique, ShouldBeEncrypted
{
    use Queueable;

    public const IN_FLIGHT_KEY_PREFIX = 'scim_repair.in_flight.';

    public const LAST_RESULT_KEY_PREFIX = 'scim_repair.last_result.';

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 300;

    public function __construct(public int $clientId)
    {
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
        Cache::forget('scim.health.'.$clientId);
    }

    public function handle(SuperOpsScimRepairService $repair, ActivityLogService $activityLog, ClientOnboardingService $onboarding): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            $this->finish(false, 'Client not found.');

            return;
        }

        $started = microtime(true);
        $result = $repair->retryExport($client, provisionMissing: true, queueSync: true);

        if ($result['ok']) {
            $onboarding->markScimExportChecklistComplete($client);
        } else {
            $onboarding->clearScimExportChecklistComplete($client);
        }

        $activityLog->log(
            'client.scim_export_retried',
            $client,
            properties: [
                'ok' => $result['ok'],
                'provisioned' => $result['provisioned'],
                'sync_queued' => $result['sync_queued'],
                'blockers' => $result['blockers'],
                'job_id' => $result['repair']['jobId'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            clientId: $client->id,
        );

        $this->finish($result['ok'], $result['message'], [
            'blockers' => $result['blockers'],
            'provisioned' => $result['provisioned'],
            'sync_queued' => $result['sync_queued'],
            'health_ok' => $result['health']['ok'] ?? false,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(false, $exception?->getMessage() ?? 'Retry SCIM export job failed.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(bool $success, string $message, array $extra = []): void
    {
        Cache::forget(self::IN_FLIGHT_KEY_PREFIX.$this->clientId);
        Cache::forget('scim.health.'.$this->clientId);
        Cache::put(self::LAST_RESULT_KEY_PREFIX.$this->clientId, array_merge([
            'success' => $success,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], $extra), now()->addDays(7));

        if (! $success) {
            Log::warning('RepairSuperOpsScimExportJob finished with errors', [
                'client_id' => $this->clientId,
                'message' => $message,
                'extra' => $extra,
            ]);
        }
    }
}
