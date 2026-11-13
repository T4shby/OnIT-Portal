<?php

namespace App\Services\EntraSync;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Security groups a new starter can be joined to.
 *
 * Microsoft 365 groups and dynamic groups are left out: engineers add people
 * to static security groups, and dynamic membership cannot be ticked on a form.
 */
class EntraSecurityGroupDirectory
{
    public function __construct(private MicrosoftGraphClient $graph) {}

    /**
     * @return list<array{id: string, name: string}>
     */
    public function optionsForClient(?Client $client): array
    {
        if ($client === null || ! filled($client->entra_tenant_id) || ! $this->graph->isConfigured()) {
            return [];
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (is_array($cached)) {
            return $this->selectable($cached);
        }

        try {
            $groups = $this->graph->listTenantGroups((string) $client->entra_tenant_id);
        } catch (Throwable) {
            return [];
        }

        Cache::put($this->cacheKey($client->id), $groups, now()->addMinutes(30));

        return $this->selectable($groups);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function namesForIds(?Client $client, array $ids): array
    {
        $allowed = [];
        foreach ($this->optionsForClient($client) as $option) {
            $allowed[$option['id']] = $option['name'];
        }

        $names = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($allowed[$id])) {
                $names[] = $allowed[$id];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array{id: string, name: string}>
     */
    public function selectable(array $groups): array
    {
        $options = [];

        foreach ($groups as $group) {
            if (! is_array($group) || ! ($group['securityEnabled'] ?? false)) {
                continue;
            }

            $types = is_array($group['groupTypes'] ?? null) ? $group['groupTypes'] : [];
            if (in_array('Unified', $types, true) || in_array('DynamicMembership', $types, true)) {
                continue;
            }

            $id = trim((string) ($group['id'] ?? ''));
            $name = trim((string) ($group['displayName'] ?? $group['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $options[] = ['id' => $id, 'name' => $name];
        }

        usort($options, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return array_values(array_slice($options, 0, 200));
    }

    private function cacheKey(int $clientId): string
    {
        return 'new_starter_security_groups.'.$clientId;
    }
}
