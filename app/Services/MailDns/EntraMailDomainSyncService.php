<?php

namespace App\Services\MailDns;

use App\Models\Client;
use App\Models\ClientDomain;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Support\MailDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase B: pull Entra verified domains (and/or mailbox email suffixes) into portal suggestions,
 * seed empty primary_domain, and expose portal vs Entra cross-check.
 */
class EntraMailDomainSyncService
{
    public const CACHE_KEY_PREFIX = 'entra.mail_domains.';

    public function __construct(
        private MicrosoftGraphClient $graph,
        private ClientDomainService $domains,
    ) {}

    public function cacheKey(int $clientId): string
    {
        return self::CACHE_KEY_PREFIX.$clientId;
    }

    /**
     * @return array{
     *   ok: bool,
     *   summary: string,
     *   verified: list<array{domain: string, is_default: bool, is_initial: bool, is_onmicrosoft: bool}>,
     *   from_mailboxes: list<array{domain: string, mailbox_count: int}>,
     *   suggested_primary: ?string,
     *   seeded_primary: bool,
     *   imported: list<string>,
     *   cross_check: array{portal_only: list<string>, entra_only: list<string>, both: list<string>},
     *   domains_api_error: ?string,
     * }
     */
    public function sync(Client $client, bool $importMissing = true, bool $seedPrimaryIfEmpty = true): array
    {
        $tenantId = trim((string) $client->entra_tenant_id);
        if ($tenantId === '' || ! $this->graph->isConfigured()) {
            return $this->emptyResult('Connect Microsoft (tenant ID) and Entra sync credentials are required.');
        }

        $verified = [];
        $domainsApiError = null;

        try {
            foreach ($this->graph->listTenantDomains($tenantId) as $row) {
                if (! $row['is_verified']) {
                    continue;
                }
                $verified[] = [
                    'domain' => $row['domain'],
                    'is_default' => $row['is_default'],
                    'is_initial' => $row['is_initial'],
                    'is_onmicrosoft' => $row['is_onmicrosoft'],
                ];
            }
        } catch (Throwable $e) {
            $domainsApiError = $e->getMessage();
            Log::info('Entra domains API unavailable; falling back to mailbox suffixes', [
                'client_id' => $client->id,
                'error' => $domainsApiError,
            ]);
        }

        $fromMailboxes = [];
        try {
            $fromMailboxes = $this->graph->listMailDomainsFromUsers($tenantId);
        } catch (Throwable $e) {
            Log::warning('Entra mailbox domain scan failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            if ($verified === [] && $domainsApiError !== null) {
                return $this->emptyResult(
                    'Could not read Entra domains or user mailboxes: '.$e->getMessage(),
                    domainsApiError: $domainsApiError,
                );
            }
        }

        $customVerified = array_values(array_filter(
            $verified,
            static fn (array $row): bool => ! $row['is_onmicrosoft'],
        ));

        $suggestedPrimary = $this->pickSuggestedPrimary($customVerified, $fromMailboxes);
        $seeded = false;
        $imported = [];

        $primarySource = $customVerified !== [] ? 'entra' : 'entra_mail';

        if ($seedPrimaryIfEmpty && ! filled($client->primary_domain) && $suggestedPrimary !== null) {
            $this->domains->setPrimaryDomain($client, $suggestedPrimary, queueCheck: true, source: $primarySource);
            $client->refresh();
            $seeded = true;
            $imported[] = $suggestedPrimary;
        }

        if ($importMissing) {
            foreach ($customVerified as $row) {
                $domain = $row['domain'];
                if ($this->portalHasDomain($client, $domain)) {
                    continue;
                }
                if (in_array($domain, $imported, true)) {
                    continue;
                }
                $this->domains->addDomain($client, $domain, makePrimary: false, source: 'entra');
                $imported[] = $domain;
            }
        }

        $client->refresh();
        $client->load('domains');
        $cross = $this->crossCheck($client, $customVerified, $fromMailboxes);

        $payload = [
            'ok' => true,
            'summary' => $this->summarise($seeded, $imported, $customVerified, $fromMailboxes, $domainsApiError),
            'verified' => $customVerified,
            'from_mailboxes' => $fromMailboxes,
            'suggested_primary' => $suggestedPrimary,
            'seeded_primary' => $seeded,
            'imported' => $imported,
            'cross_check' => $cross,
            'domains_api_error' => $domainsApiError,
            'fetched_at' => now()->toIso8601String(),
        ];

        Cache::put($this->cacheKey($client->id), $payload, now()->addDay());

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cached(Client $client): ?array
    {
        $cached = Cache::get($this->cacheKey($client->id));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  list<array{domain: string, is_default: bool, is_initial: bool, is_onmicrosoft: bool}>  $customVerified
     * @param  list<array{domain: string, mailbox_count: int}>  $fromMailboxes
     * @return array{portal_only: list<string>, entra_only: list<string>, both: list<string>}
     */
    public function crossCheck(Client $client, array $customVerified, array $fromMailboxes): array
    {
        $portal = ClientDomain::query()
            ->where('client_id', $client->id)
            ->pluck('domain')
            ->map(fn ($d) => strtolower((string) $d))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $entra = [];
        foreach ($customVerified as $row) {
            $entra[$row['domain']] = true;
        }
        foreach ($fromMailboxes as $row) {
            $entra[$row['domain']] = true;
        }
        $entraList = array_keys($entra);

        return [
            'portal_only' => array_values(array_diff($portal, $entraList)),
            'entra_only' => array_values(array_diff($entraList, $portal)),
            'both' => array_values(array_intersect($portal, $entraList)),
        ];
    }

    /**
     * @param  list<array{domain: string, is_default: bool, is_initial: bool, is_onmicrosoft: bool}>  $customVerified
     * @param  list<array{domain: string, mailbox_count: int}>  $fromMailboxes
     */
    private function pickSuggestedPrimary(array $customVerified, array $fromMailboxes): ?string
    {
        foreach ($customVerified as $row) {
            if ($row['is_default'] && ! $row['is_onmicrosoft']) {
                return $row['domain'];
            }
        }
        foreach ($customVerified as $row) {
            if (! $row['is_initial'] && ! $row['is_onmicrosoft']) {
                return $row['domain'];
            }
        }
        if ($customVerified !== []) {
            return $customVerified[0]['domain'];
        }
        if ($fromMailboxes !== []) {
            $domain = MailDomain::normalize($fromMailboxes[0]['domain'] ?? null);

            return $domain;
        }

        return null;
    }

    private function portalHasDomain(Client $client, string $domain): bool
    {
        return ClientDomain::query()
            ->where('client_id', $client->id)
            ->where('domain', $domain)
            ->exists();
    }

    /**
     * @param  list<string>  $imported
     * @param  list<array{domain: string, is_default: bool, is_initial: bool, is_onmicrosoft: bool}>  $customVerified
     * @param  list<array{domain: string, mailbox_count: int}>  $fromMailboxes
     */
    private function summarise(
        bool $seeded,
        array $imported,
        array $customVerified,
        array $fromMailboxes,
        ?string $domainsApiError,
    ): string {
        $parts = [];
        if ($seeded) {
            $parts[] = 'Set main domain from Entra/mailboxes';
        }
        if ($imported !== []) {
            $parts[] = 'Imported '.count($imported).' domain(s)';
        }
        $parts[] = count($customVerified).' verified custom domain(s)';
        $parts[] = count($fromMailboxes).' mailbox domain(s)';
        if ($domainsApiError !== null) {
            $parts[] = 'Domain.Read.All not available - used mailbox suffixes (add Domain.Read.All + re-consent for verified-domain list)';
        }

        return implode('. ', $parts).'.';
    }

    /**
     * @return array{
     *   ok: bool,
     *   summary: string,
     *   verified: list<array{domain: string, is_default: bool, is_initial: bool, is_onmicrosoft: bool}>,
     *   from_mailboxes: list<array{domain: string, mailbox_count: int}>,
     *   suggested_primary: ?string,
     *   seeded_primary: bool,
     *   imported: list<string>,
     *   cross_check: array{portal_only: list<string>, entra_only: list<string>, both: list<string>},
     *   domains_api_error: ?string,
     * }
     */
    private function emptyResult(string $summary, ?string $domainsApiError = null): array
    {
        return [
            'ok' => false,
            'summary' => $summary,
            'verified' => [],
            'from_mailboxes' => [],
            'suggested_primary' => null,
            'seeded_primary' => false,
            'imported' => [],
            'cross_check' => ['portal_only' => [], 'entra_only' => [], 'both' => []],
            'domains_api_error' => $domainsApiError,
        ];
    }
}
