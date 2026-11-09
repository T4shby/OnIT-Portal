<?php

namespace App\Services\Dropsuite;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Import Dropsuite reseller organisations (GET /users) and match them to live portal clients.
 * Staff pick the short organization_id here instead of pasting the on-screen user id.
 */
class DropsuiteOrganizationDirectory
{
    public const FILTER_LIVE = 'live';

    public const FILTER_UNMAPPED = 'unmapped';

    public const FILTER_ALL = 'all';

    public function __construct(private DropsuiteClientMetricsService $metrics) {}

    public function isConfigured(): bool
    {
        return $this->metrics->isAvailable();
    }

    /**
     * @return list<array{organization_id: string, name: string, user_count: int}>
     */
    public function organizations(bool $fresh = false): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            return $this->metrics->listResellerOrganizations($fresh);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{
     *     organization_id: string,
     *     name: string,
     *     user_count: int,
     *     mapped_client: ?Client,
     *     suggested_client: ?Client,
     *     match: string
     * }>
     */
    public function catalog(User $viewer, string $filter = self::FILTER_LIVE, bool $fresh = false): array
    {
        $orgs = $this->metrics->listResellerOrganizations($fresh);
        $clients = $this->accessibleClients($viewer);
        $mappedByOrg = $clients
            ->filter(fn (Client $client): bool => filled($client->dropsuite_organization_id))
            ->keyBy(fn (Client $client): string => (string) $client->dropsuite_organization_id);

        $rows = [];
        foreach ($orgs as $org) {
            $oid = (string) $org['organization_id'];
            $mapped = $mappedByOrg->get($oid);
            $suggested = $mapped instanceof Client
                ? null
                : $this->suggestClient($org, $clients);

            $match = $mapped instanceof Client ? 'mapped' : ($suggested instanceof Client ? 'suggested' : 'none');
            $liveMatch = ($mapped instanceof Client && $mapped->is_active)
                || ($suggested instanceof Client && $suggested->is_active);

            if ($filter === self::FILTER_LIVE && ! $liveMatch) {
                continue;
            }
            if ($filter === self::FILTER_UNMAPPED && $mapped instanceof Client) {
                continue;
            }

            $rows[] = [
                'organization_id' => $oid,
                'name' => (string) $org['name'],
                'user_count' => (int) $org['user_count'],
                'mapped_client' => $mapped instanceof Client ? $mapped : null,
                'suggested_client' => $suggested instanceof Client ? $suggested : null,
                'match' => $match,
            ];
        }

        return $rows;
    }

    /**
     * Live portal clients the viewer can see that still have no Dropsuite organization_id.
     *
     * @return Collection<int, Client>
     */
    public function unmappedLiveClients(User $viewer): Collection
    {
        return $this->accessibleClients($viewer)
            ->filter(fn (Client $client): bool => $client->is_active && blank($client->dropsuite_organization_id))
            ->values();
    }

    /**
     * @return Collection<int, Client>
     */
    public function assignableClients(User $viewer): Collection
    {
        return $this->accessibleClients($viewer)
            ->filter(fn (Client $client): bool => $client->is_active)
            ->values();
    }

    /**
     * @param  array{organization_id?: string, name?: string}  $org
     * @param  Collection<int, Client>  $clients
     */
    public function suggestClient(array $org, Collection $clients): ?Client
    {
        $orgNorm = $this->normalizeName((string) ($org['name'] ?? ''));
        if ($orgNorm === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($clients as $client) {
            if (! $client->is_active) {
                continue;
            }
            if (filled($client->dropsuite_organization_id)) {
                continue;
            }

            $score = $this->nameScore($orgNorm, $client);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $client;
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    public function normalizeName(string $value): string
    {
        $value = strtolower($value);
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = (string) preg_replace('/\[[^\]]*\]/', ' ', $value);
        $value = (string) preg_replace('/\b(ltd|limited|llp|plc|inc|llc|nfr|uk|co)\b/', ' ', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function nameScore(string $orgNorm, Client $client): int
    {
        $clientNorm = $this->normalizeName($client->name);
        if ($clientNorm !== '' && $clientNorm === $orgNorm) {
            return 100;
        }

        $domainHost = strtolower((string) $client->primary_domain);
        $domainHost = preg_replace('/^www\./', '', $domainHost) ?? $domainHost;
        $domainNorm = $this->normalizeName(explode('.', $domainHost)[0] ?? '');
        if ($domainNorm !== '' && strlen($domainNorm) >= 4 && ($domainNorm === $orgNorm || str_contains($orgNorm, $domainNorm))) {
            return 90;
        }

        if ($clientNorm !== '' && strlen($orgNorm) >= 4 && strlen($clientNorm) >= 4) {
            if (str_contains($clientNorm, $orgNorm) || str_contains($orgNorm, $clientNorm)) {
                return 80;
            }
        }

        return 0;
    }

    /**
     * @return Collection<int, Client>
     */
    private function accessibleClients(User $viewer): Collection
    {
        $ids = $viewer->accessibleClientIds();

        return Client::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }
}
