<?php

namespace App\Services\M365;

use App\Enums\EntraIdentityType;
use App\Enums\M365GroupType;
use App\Models\Client;
use App\Services\EntraSync\EntraSyncDisplayName;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class M365DirectoryService
{
    public function __construct(private MicrosoftGraphClient $graph) {}

    public function isAvailableForClient(Client $client): bool
    {
        return filled($client->entra_tenant_id) && $this->graph->isConfigured();
    }

    /**
     * @throws Throwable
     */
    public function snapshot(Client $client, bool $refresh = false): M365DirectorySnapshot
    {
        if (! $this->isAvailableForClient($client)) {
            throw new \RuntimeException('Microsoft 365 directory is not configured for this organisation.');
        }

        $cacheKey = 'm365_directory.client.'.$client->id;

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('services.entra_sync.directory_cache_minutes', 15)),
            fn () => $this->buildSnapshot($client),
        );
    }

    private function buildSnapshot(Client $client): M365DirectorySnapshot
    {
        $tenantId = $client->entra_tenant_id;

        $people = collect($this->graph->listSyncEligibleUsers($tenantId))
            ->map(function (array $user) use ($tenantId) {
                $email = strtolower($user['mail'] ?: $user['userPrincipalName'] ?? '');
                $identityType = $user['identityType'];

                $licenses = $identityType === EntraIdentityType::User
                    ? $this->graph->getUserLicenseSkuPartNumbers($tenantId, $user['id'])
                    : [];

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
                    'licenses' => $licenses,
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
}
