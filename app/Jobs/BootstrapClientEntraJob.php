<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\EntraSync\CustomerEntraBootstrapService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs CustomerEntraBootstrapService::bootstrap() off the HTTP request.
 * It makes a long chain of sequential Graph calls (including consent-propagation
 * waits and service-principal polling) that can exceed nginx's 60s gateway -
 * same reason ApplySuperOpsScimJob/RepairSuperOpsScimExportJob are queued.
 */
class BootstrapClientEntraJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public const IN_FLIGHT_KEY_PREFIX = 'entra_bootstrap.in_flight.';

    public const LAST_RESULT_KEY_PREFIX = 'entra_bootstrap.last_result.';

    public int $tries = 1;

    public int $timeout = 300;

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
    }

    public function handle(CustomerEntraBootstrapService $bootstrap, ActivityLogService $activityLog): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || ! filled($client->entra_tenant_id)) {
            $this->finish(false, 'Client is missing an Entra tenant ID.');

            return;
        }

        $started = microtime(true);

        try {
            $result = $bootstrap->bootstrap($client, $client->entra_tenant_id);
        } catch (\Throwable $e) {
            Log::warning('Background Entra bootstrap failed', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            $this->finish(false, $e->getMessage(), [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return;
        }

        $activityLog->log(
            'client.entra_bootstrap',
            $client,
            properties: [
                'ok' => $result['ok'],
                'details' => $result['details'],
                'warnings' => $result['warnings'],
                'background' => true,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            clientId: $client->id,
        );

        $message = $result['summary'];
        if ($result['details'] !== []) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 6));
        }

        $this->finish($result['ok'], $message, [
            'warnings' => $result['warnings'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(false, $exception?->getMessage() ?? 'Entra bootstrap job failed.');
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
