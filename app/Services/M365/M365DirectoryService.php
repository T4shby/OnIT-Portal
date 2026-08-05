<?php

namespace App\Services\M365;

use App\Enums\EntraIdentityType;
use App\Enums\M365GroupType;
use App\Jobs\RefreshM365DirectoryJob;
use App\Models\Client;
use App\Services\EntraSync\EntraSyncDisplayName;
use App\Services\EntraSync\MicrosoftGraphClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class M365DirectoryService
{
    public function __construct(private MicrosoftGraphClient $graph) {}

    public function isAvailableForClient(Client $client): bool
    {
        return filled($client->entra_tenant_id) && $this->graph->isConfigured();
    }

    public function displaySnapshot(Client $client, bool $manualRefresh = false): M365DirectoryDisplayResult
    {
        if (! $this->isAvailableForClient($client)) {
            throw new \RuntimeException('Microsoft 365 directory is not configured for this organisation.');
        }

        if ($manualRefresh) {
            $this->queueRefresh($client, respectCooldown: true);

            return $this->buildDisplayResult($client, refreshQueued: true);
        }

        $snapshot = $this->readStoredSnapshot($client);
        $meta = $this->readMeta($client);

        if ($snapshot === null) {
            $this->queueRefresh($client);

            return new M365DirectoryDisplayResult(
                snapshot: null,
                isStale: true,
                refreshQueued: true,
                refreshInProgress: $this->refreshInProgress($client),
                lastRefreshedAt: null,
                statusMessage: 'Directory data has not been synchronised yet. A refresh is in progress.',
            );
        }

        $isStale = $this->isStale($meta);

        // Do not auto-queue on every stale page view — prewarm / Refresh now owns that.
        // Auto-queue was re-setting "in progress" forever when jobs lagged.

        return $this->buildDisplayResult($client, snapshot: $snapshot, isStale: $isStale);
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (! $this->isAvailableForClient($client)) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client);

        if ($respectCooldown) {
            $cooldownKey = 'm365_directory.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }
            Cache::put($cooldownKey, true, now()->addSeconds(
                (int) config('services.entra_sync.directory_refresh_cooldown_seconds', 60),
            ));
        }

        if ($this->refreshInProgress($client)) {
            return false;
        }

        Cache::put('m365_directory.refresh_queued.'.$client->id, true, now()->addMinutes(15));

        RefreshM365DirectoryJob::dispatch($client->id);

        return true;
    }

    /**
     * Cold (no snapshot) or past directory fresh window — used by prewarm.
     */
    public function needsBackgroundRefresh(Client $client): bool
    {
        if (! $this->isAvailableForClient($client)) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client);

        if ($this->refreshInProgress($client)) {
            return false;
        }

        $snapshot = $this->readStoredSnapshot($client);
        if ($snapshot === null) {
            return true;
        }

        return $this->isStale($this->readMeta($client));
    }

    /**
     * @throws Throwable
     */
    public function buildAndStoreSnapshot(Client $client): M365DirectorySnapshot
    {
        $snapshot = $this->buildSnapshot($client);
        $cacheKey = $this->snapshotCacheKey($client);

        Cache::put(
            $cacheKey,
            $snapshot,
            now()->addMinutes((int) config('services.entra_sync.directory_stale_minutes', 1440)),
        );

        Cache::put($this->metaCacheKey($client), [
            'refreshed_at' => $snapshot->refreshedAt->toIso8601String(),
        ], now()->addMinutes((int) config('services.entra_sync.directory_stale_minutes', 1440)));

        return $snapshot;
    }

    /**
     * @deprecated Use displaySnapshot() for non-blocking reads.
     *
     * @throws Throwable
     */
    public function snapshot(Client $client, bool $refresh = false): M365DirectorySnapshot
    {
        if ($refresh) {
            $this->queueRefresh($client, respectCooldown: true);
        }

        $display = $this->displaySnapshot($client);

        if ($display->snapshot !== null) {
            return $display->snapshot;
        }

        return new M365DirectorySnapshot(collect(), collect(), now());
    }

    private function buildDisplayResult(
        Client $client,
        ?M365DirectorySnapshot $snapshot = null,
        bool $isStale = false,
        bool $refreshQueued = false,
    ): M365DirectoryDisplayResult {
        $snapshot ??= $this->readStoredSnapshot($client);
        $meta = $this->readMeta($client);
        $lastRefreshedAt = $snapshot?->refreshedAt ?? $this->parseRefreshedAt($meta['refreshed_at'] ?? null);
        $refreshInProgress = $this->refreshInProgress($client);

        $statusMessage = null;
        if ($isStale && $lastRefreshedAt) {
            $statusMessage = 'Showing cached data last refreshed at '
                .$lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i').' UK.';
        }

        return new M365DirectoryDisplayResult(
            snapshot: $snapshot,
            isStale: $isStale,
            refreshQueued: $refreshQueued || $refreshInProgress,
            refreshInProgress: $refreshInProgress,
            lastRefreshedAt: $lastRefreshedAt,
            statusMessage: $statusMessage,
        );
    }

    private function buildSnapshot(Client $client): M365DirectorySnapshot
    {
        $tenantId = $client->entra_tenant_id;

        $people = collect($this->graph->listSyncEligibleUsers($tenantId))
            ->map(function (array $user) {
                $email = strtolower($user['mail'] ?: $user['userPrincipalName'] ?? '');
                $identityType = $user['identityType'];

                return [
                    'displayName' => EntraSyncDisplayName::format(
                        $user['displayName'],
                        $identityType,
                        $email ?: null,
                    ),
                    'email' => $email ?: null,
                    'type' => $identityType->value,
                    'typeLabel' => $identityType->displaySuffix(),
                    'accountEnabled' => $user['accountEnabled'],
                    'licenses' => $identityType === EntraIdentityType::User
                        ? ($user['licenseSkuPartNumbers'] ?? [])
                        : [],
                    'portalLogin' => $identityType === EntraIdentityType::User && $user['accountEnabled'],
                ];
            })
            ->sortBy('displayName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $groups = collect($this->graph->listTenantGroups($tenantId))
            ->map(function (array $group) {
                $type = M365GroupType::classify($group);

                return [
                    'displayName' => $group['displayName'] ?: 'Unnamed group',
                    'email' => $group['mail'] ?? null,
                    'type' => $type->value,
                    'typeLabel' => $type->label(),
                    'description' => $group['description'] ?? null,
                ];
            })
            ->sortBy('displayName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return new M365DirectorySnapshot($people, $groups, now());
    }

    private function readStoredSnapshot(Client $client): ?M365DirectorySnapshot
    {
        $value = Cache::get($this->snapshotCacheKey($client));

        return $value instanceof M365DirectorySnapshot ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(Client $client): array
    {
        $meta = Cache::get($this->metaCacheKey($client));

        return is_array($meta) ? $meta : [];
    }

    private function isStale(array $meta): bool
    {
        $refreshedAt = $this->parseRefreshedAt($meta['refreshed_at'] ?? null);

        if ($refreshedAt === null) {
            return true;
        }

        return $refreshedAt->lte(now()->subMinutes(
            (int) config('services.entra_sync.directory_cache_minutes', 15),
        ));
    }

    private function refreshInProgress(Client $client): bool
    {
        return Cache::has('m365_directory.refresh_queued.'.$client->id);
    }

    private function clearOrphanedRefreshFlags(Client $client): void
    {
        if (! Cache::has('m365_directory.refresh_queued.'.$client->id)) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            return;
        }

        $pending = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('payload', 'like', '%RefreshM365DirectoryJob%')
            ->where('payload', 'like', '%clientId";i:'.$client->id.';%')
            ->exists();

        if (! $pending) {
            Cache::forget('m365_directory.refresh_queued.'.$client->id);
            Cache::forget('m365_directory.refresh_started.'.$client->id);
        }
    }

    private function parseRefreshedAt(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    private function snapshotCacheKey(Client $client): string
    {
        return 'm365_directory.client.'.$client->id;
    }

    private function metaCacheKey(Client $client): string
    {
        return 'm365_directory.meta.'.$client->id;
    }
}
