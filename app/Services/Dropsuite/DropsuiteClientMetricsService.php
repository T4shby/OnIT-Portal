<?php

namespace App\Services\Dropsuite;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DropsuiteClientMetricsService
{
    public function __construct(private DropsuiteApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): DropsuiteClientBackupSummary
    {
        if (! $this->isAvailable()) {
            return $this->unavailableSummary('Dropsuite API is not configured.');
        }

        if (empty($client->dropsuite_organization_id)) {
            return $this->unavailableSummary('Dropsuite is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);

        if ($manualRefresh) {
            $this->queueRefresh($client);
        } else {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $summary = $this->summaryFromCache($client->id, $cached);
                if ($summary->isStale && ! $summary->refreshInProgress) {
                    $this->queueRefresh($client);
                }

                return $summary;
            }
        }

        $this->queueRefresh($client);

        $stale = Cache::get($cacheKey);

        if (is_array($stale)) {
            return $this->summaryFromCache($client->id, $stale, refreshInProgress: true);
        }

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: null,
            lastBackupStatus: 'unknown',
            failedBackupsCount: null,
            available: false,
            unavailableReason: 'Data has not been synchronised yet.',
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (empty($client->dropsuite_organization_id) || ! $this->isAvailable()) {
            return false;
        }

        if ($respectCooldown) {
            $cooldownKey = 'dropsuite_backup.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }
            Cache::put($cooldownKey, true, now()->addSeconds(60));
        }

        if (Cache::has('dropsuite_backup.refresh_queued.'.$client->id)) {
            return false;
        }

        Cache::put('dropsuite_backup.refresh_queued.'.$client->id, true, now()->addMinutes(5));
        RefreshDropsuiteBackupJob::dispatch($client->id);

        return true;
    }

    public function refreshAndStore(Client $client): DropsuiteClientBackupSummary
    {
        $organizationId = (string) $client->dropsuite_organization_id;
        $lock = Cache::lock('dropsuite_backup.refresh.'.$client->id, 300);

        if (! $lock->get()) {
            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }

            return $this->unavailableSummary('Dropsuite backup refresh is already in progress.');
        }

        try {
            $payload = $this->fetchOrganizationBackupSummary($organizationId);
            $payload['last_refreshed_at'] = now()->toIso8601String();

            Cache::put(
                $this->cacheKey($client->id),
                $payload,
                now()->addMinutes(1440),
            );

            return $this->summaryFromCache($client->id, $payload);
        } catch (Throwable $e) {
            Log::warning('Dropsuite backup refresh failed', [
                'client_id' => $client->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, isStale: true);
            }

            return $this->unavailableSummary('Dropsuite backup data is currently unavailable.');
        } finally {
            Cache::forget('dropsuite_backup.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrganizationBackupSummary(string $organizationId): array
    {
        $payload = $this->api->get("organizations/{$organizationId}/backup-summary/");
        $summary = $payload['data'] ?? $payload;

        if (! is_array($summary)) {
            return [];
        }

        $protectedMailboxes = $this->firstInt($summary, [
            'protectedMailboxes',
            'protected_mailboxes',
            'protected_mailboxes_count',
            'mailboxes_protected',
            'total_protected_mailboxes',
        ]);

        if ($protectedMailboxes === null && is_array($summary['mailboxes'] ?? null)) {
            $protectedMailboxes = $this->firstInt($summary['mailboxes'], ['protected', 'protected_count', 'total']);
        }

        $failedBackupsCount = $this->firstInt($summary, [
            'failedBackupsCount',
            'failed_backups_count',
            'failed_backups',
            'backup_failures',
            'failures',
        ]);

        if ($failedBackupsCount === null && is_array($summary['backup_summary'] ?? null)) {
            $failedBackupsCount = $this->firstInt($summary['backup_summary'], ['failed', 'failures']);
        }

        return [
            'protected_mailboxes' => $protectedMailboxes,
            'last_backup_status' => $this->backupStatus($summary, $failedBackupsCount),
            'failed_backups_count' => $failedBackupsCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        int $clientId,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
    ): DropsuiteClientBackupSummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $isStale = $lastRefreshedAt->lte(now()->subMinutes(10));
        }

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: $payload['protected_mailboxes'] ?? null,
            lastBackupStatus: $this->validStatus($payload['last_backup_status'] ?? null),
            failedBackupsCount: $payload['failed_backups_count'] ?? null,
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || Cache::has('dropsuite_backup.refresh_queued.'.$clientId),
        );
    }

    private function unavailableSummary(string $reason): DropsuiteClientBackupSummary
    {
        return new DropsuiteClientBackupSummary(
            protectedMailboxes: null,
            lastBackupStatus: 'unknown',
            failedBackupsCount: null,
            available: false,
            unavailableReason: $reason,
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstInt(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function backupStatus(array $payload, ?int $failedBackupsCount): string
    {
        $status = $this->validStatus($payload['lastBackupStatus'] ?? $payload['last_backup_status'] ?? null);

        if ($status !== 'unknown') {
            return $status;
        }

        if ($failedBackupsCount !== null) {
            return $failedBackupsCount > 0 ? 'warning' : 'success';
        }

        return 'unknown';
    }

    private function validStatus(mixed $status): string
    {
        $status = strtolower((string) $status);

        return in_array($status, ['success', 'warning'], true) ? $status : 'unknown';
    }

    private function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:dropsuite-backup:v1";
    }
}
