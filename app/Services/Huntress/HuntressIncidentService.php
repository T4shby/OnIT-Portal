<?php

namespace App\Services\Huntress;

use App\Models\Client;
use App\Models\User;
use App\Services\Portal\PortalFreshnessService;
use App\Services\Portal\ClientVisibilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Client-scoped Huntress incident reports (active / resolved + detail).
 *
 * Cache: client:{id}:huntress-incidents:v1
 * Visibility: ClientVisibilityService (org-wide admin vs personal match).
 */
class HuntressIncidentService
{
    private const STALE_RETENTION_MINUTES = 1440;

    private const MAX_PAGES = 25;

    private const PAGE_LIMIT = 100;

    public function __construct(
        private HuntressApiClient $api,
        private ClientVisibilityService $visibility,
    ) {}

    public function isAvailableForClient(Client $client): bool
    {
        return $this->api->isConfigured() && filled($client->huntress_organization_id);
    }

    public function userCanViewAllCases(User $user, Client $client): bool
    {
        return $this->visibility->canViewOrganisationWide($user, $client);
    }

    public function userCanAccessArea(User $user, Client $client): bool
    {
        return $this->visibility->canAccessClientSystems($user, $client)
            && $this->isAvailableForClient($client);
    }

    public function userCanViewIncident(User $user, Client $client, HuntressIncident $incident): bool
    {
        if (! $this->userCanAccessArea($user, $client)) {
            return false;
        }

        if ($this->userCanViewAllCases($user, $client)) {
            return true;
        }

        return $this->incidentMatchesUser($incident, $user);
    }

    public function listForClient(Client $client, ?string $filter = null, ?User $viewer = null): HuntressIncidentListSnapshot
    {
        if (! $this->api->isConfigured()) {
            return $this->unavailable('Security monitoring is not configured.');
        }

        if (! filled($client->huntress_organization_id)) {
            return $this->unavailable('Huntress is not connected for this organisation.');
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (! is_array($cached)) {
            return new HuntressIncidentListSnapshot(
                incidents: [],
                activeCount: 0,
                resolvedCount: 0,
                available: false,
                unavailableReason: 'Security cases have not been synchronised yet.',
                lastRefreshedAt: null,
                isStale: true,
                refreshInProgress: Cache::has('huntress_security.refresh_queued.'.$client->id),
            );
        }

        $snapshot = $this->snapshotFromCache($client->id, $cached);
        $incidents = $snapshot->incidents;

        // Regular users: only their incidents. Admins / staff: full org list.
        if ($viewer !== null && ! $this->userCanViewAllCases($viewer, $client)) {
            $incidents = array_values(array_filter(
                $incidents,
                fn (HuntressIncident $i): bool => $this->incidentMatchesUser($i, $viewer),
            ));
        }

        if ($filter === 'active') {
            $incidents = array_values(array_filter($incidents, fn (HuntressIncident $i): bool => $i->isActive));
        } elseif ($filter === 'resolved') {
            $incidents = array_values(array_filter($incidents, fn (HuntressIncident $i): bool => ! $i->isActive));
        }

        $active = 0;
        $resolved = 0;
        foreach ($incidents as $incident) {
            if ($incident->isActive) {
                $active++;
            } else {
                $resolved++;
            }
        }

        // For restricted viewers, recount from their subset; admins keep cached org totals when unfiltered is better for banner.
        $useSubsetCounts = $viewer !== null && ! $this->userCanViewAllCases($viewer, $client);

        return new HuntressIncidentListSnapshot(
            incidents: $incidents,
            activeCount: $useSubsetCounts ? $active : $snapshot->activeCount,
            resolvedCount: $useSubsetCounts ? $resolved : $snapshot->resolvedCount,
            available: $snapshot->available,
            unavailableReason: $snapshot->unavailableReason,
            lastRefreshedAt: $snapshot->lastRefreshedAt,
            isStale: $snapshot->isStale,
            refreshInProgress: $snapshot->refreshInProgress,
        );
    }

    /**
     * Detail for one incident. Enforces org ownership + per-user visibility.
     */
    public function findForClient(Client $client, string $incidentId, ?User $viewer = null): ?HuntressIncident
    {
        if (! filled($client->huntress_organization_id) || ! $this->api->isConfigured()) {
            return null;
        }

        $expectedOrg = (string) $client->huntress_organization_id;

        $list = Cache::get($this->cacheKey($client->id));
        if (is_array($list)) {
            foreach ($list['incidents'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((string) ($row['id'] ?? '') !== $incidentId) {
                    continue;
                }
                if ((string) ($row['organization_id'] ?? '') !== $expectedOrg) {
                    return null;
                }

                $incident = $this->incidentFromRow($row);
                if ($viewer !== null && ! $this->userCanViewIncident($viewer, $client, $incident)) {
                    return null;
                }

                return $incident;
            }
        }

        try {
            $payload = $this->api->get('incident_reports/'.$incidentId);
            $raw = is_array($payload['incident_report'] ?? null)
                ? $payload['incident_report']
                : $payload;
            $row = $this->normalizeApiReport($raw);
            if ((string) ($row['organization_id'] ?? '') !== $expectedOrg) {
                Log::warning('Huntress incident blocked: organisation mismatch', [
                    'client_id' => $client->id,
                    'incident_id' => $incidentId,
                    'expected_org' => $expectedOrg,
                    'got_org' => $row['organization_id'] ?? null,
                ]);

                return null;
            }

            $incident = $this->incidentFromRow($row);
            if ($viewer !== null && ! $this->userCanViewIncident($viewer, $client, $incident)) {
                return null;
            }

            return $incident;
        } catch (Throwable $e) {
            Log::error('Huntress incident detail fetch failed', [
                'client_id' => $client->id,
                'incident_id' => $incidentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Personal visibility: the viewer's exact email must be one of the
     * incident's related addresses (remediation approver, or a full address
     * named in the report). No substring matching on the subject/body/email
     * local part/display name: "mark@" would otherwise match "marketing@".
     */
    public function incidentMatchesUser(HuntressIncident $incident, User $user): bool
    {
        foreach ($incident->relatedEmails as $email) {
            if ($this->visibility->matchesEmail($email, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{active_count: int, resolved_count: int, incidents: list<array<string, mixed>>}
     */
    public function refreshAndStore(Client $client): array
    {
        $organizationId = (string) $client->huntress_organization_id;
        if ($organizationId === '') {
            throw new RuntimeException('Client has no Huntress organisation id.');
        }

        $rows = $this->fetchAllForOrganization($organizationId);
        $active = 0;
        $resolved = 0;
        foreach ($rows as $row) {
            if ($this->isActiveStatus((string) ($row['status'] ?? ''))) {
                $active++;
            } else {
                $resolved++;
            }
        }

        $payload = [
            'organization_id' => $organizationId,
            'active_count' => $active,
            'resolved_count' => $resolved,
            'incidents' => $rows,
            'last_refreshed_at' => now()->toIso8601String(),
        ];

        Cache::put(
            $this->cacheKey($client->id),
            $payload,
            now()->addMinutes(self::STALE_RETENTION_MINUTES),
        );

        return $payload;
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:huntress-incidents:v1";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllForOrganization(string $organizationId): array
    {
        $collected = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = [
                'organization_id' => $organizationId,
                'limit' => self::PAGE_LIMIT,
            ];
            if ($pageToken !== null) {
                $query['page_token'] = $pageToken;
            }

            $payload = $this->api->get('incident_reports', $query);
            $batch = $payload['incident_reports'] ?? $payload['data'] ?? [];
            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                $row = $this->normalizeApiReport($raw);
                if ((string) ($row['organization_id'] ?? '') !== $organizationId) {
                    continue;
                }
                $collected[] = $row;
            }

            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
            $pageToken = $pagination['next_page_token'] ?? null;
            if (! filled($pageToken)) {
                break;
            }
        }

        usort($collected, function (array $a, array $b): int {
            $aActive = $this->isActiveStatus((string) ($a['status'] ?? '')) ? 0 : 1;
            $bActive = $this->isActiveStatus((string) ($b['status'] ?? '')) ? 0 : 1;
            if ($aActive !== $bActive) {
                return $aActive <=> $bActive;
            }

            $aTime = $a['sent_at'] ?? $a['updated_at'] ?? '';
            $bTime = $b['sent_at'] ?? $b['updated_at'] ?? '';

            return strcmp((string) $bTime, (string) $aTime);
        });

        return $collected;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeApiReport(array $raw): array
    {
        $remediations = [];
        $relatedEmails = [];
        $remBlock = $raw['remediations'] ?? null;
        $items = is_array($remBlock) ? ($remBlock['items'] ?? $remBlock) : [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $remediations[] = [
                    'action' => (string) ($item['action'] ?? $item['type'] ?? 'Remediation'),
                    'status' => (string) ($item['status'] ?? ''),
                ];
                $approvedBy = $item['approved_by'] ?? null;
                if (is_array($approvedBy) && filled($approvedBy['email'] ?? null)) {
                    $relatedEmails[] = strtolower((string) $approvedBy['email']);
                }
            }
        }

        $indicators = $raw['indicator_types'] ?? [];
        if (! is_array($indicators)) {
            $indicators = [];
        }

        $summary = $raw['summary'] ?? $raw['body'] ?? null;
        $body = $raw['body'] ?? null;
        if (is_string($body)) {
            $body = trim(strip_tags($body));
        } else {
            $body = null;
        }
        if (is_string($summary)) {
            $summary = trim(strip_tags($summary));
        } else {
            $summary = null;
        }

        $relatedEmails = array_values(array_unique(array_merge(
            $relatedEmails,
            $this->extractEmails(implode(' ', array_filter([$summary, $body, (string) ($raw['subject'] ?? '')]))),
        )));

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'organization_id' => (string) ($raw['organization_id'] ?? ''),
            'subject' => (string) ($raw['subject'] ?? 'Security case'),
            'status' => strtolower((string) ($raw['status'] ?? 'unknown')),
            'severity' => isset($raw['severity']) ? (string) $raw['severity'] : null,
            'summary' => $summary,
            'body' => $body,
            'sent_at' => $this->isoOrNull($raw['sent_at'] ?? null),
            'closed_at' => $this->isoOrNull($raw['closed_at'] ?? null),
            'updated_at' => $this->isoOrNull($raw['updated_at'] ?? $raw['status_updated_at'] ?? null),
            'platform' => isset($raw['platform']) ? (string) $raw['platform'] : null,
            'indicator_types' => array_values(array_map('strval', $indicators)),
            'remediations' => $remediations,
            'related_emails' => $relatedEmails,
        ];
    }

    public function isActiveStatus(string $status): bool
    {
        $status = strtolower(trim($status));

        return ! in_array($status, ['closed', 'resolved', 'complete', 'completed'], true);
    }

    /**
     * @return list<string>
     */
    private function extractEmails(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function snapshotFromCache(int $clientId, array $payload): HuntressIncidentListSnapshot
    {
        $last = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $isStale = false;
        if ($last) {
            $soft = max(1, app(PortalFreshnessService::class)->effectiveSoftWindowMinutes());
            $isStale = $last->lte(now()->subMinutes($soft));
        }

        $incidents = [];
        foreach ($payload['incidents'] ?? [] as $row) {
            if (is_array($row)) {
                $incidents[] = $this->incidentFromRow($row);
            }
        }

        return new HuntressIncidentListSnapshot(
            incidents: $incidents,
            activeCount: (int) ($payload['active_count'] ?? 0),
            resolvedCount: (int) ($payload['resolved_count'] ?? 0),
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $last,
            isStale: $isStale,
            refreshInProgress: Cache::has('huntress_security.refresh_queued.'.$clientId),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function incidentFromRow(array $row): HuntressIncident
    {
        $status = (string) ($row['status'] ?? 'unknown');
        $related = $row['related_emails'] ?? [];
        if (! is_array($related)) {
            $related = [];
        }

        return new HuntressIncident(
            id: (string) ($row['id'] ?? ''),
            subject: (string) ($row['subject'] ?? 'Security case'),
            status: $status,
            isActive: $this->isActiveStatus($status),
            severity: isset($row['severity']) ? (string) $row['severity'] : null,
            summary: isset($row['summary']) ? (string) $row['summary'] : null,
            body: isset($row['body']) ? (string) $row['body'] : null,
            sentAt: filled($row['sent_at'] ?? null) ? Carbon::parse($row['sent_at']) : null,
            closedAt: filled($row['closed_at'] ?? null) ? Carbon::parse($row['closed_at']) : null,
            updatedAt: filled($row['updated_at'] ?? null) ? Carbon::parse($row['updated_at']) : null,
            platform: isset($row['platform']) ? (string) $row['platform'] : null,
            indicatorTypes: is_array($row['indicator_types'] ?? null) ? $row['indicator_types'] : [],
            remediations: is_array($row['remediations'] ?? null) ? $row['remediations'] : [],
            organizationId: (string) ($row['organization_id'] ?? ''),
            relatedEmails: array_values(array_map('strval', $related)),
        );
    }

    private function unavailable(string $reason): HuntressIncidentListSnapshot
    {
        return new HuntressIncidentListSnapshot(
            incidents: [],
            activeCount: 0,
            resolvedCount: 0,
            available: false,
            unavailableReason: $reason,
            lastRefreshedAt: null,
        );
    }

    private function isoOrNull(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
