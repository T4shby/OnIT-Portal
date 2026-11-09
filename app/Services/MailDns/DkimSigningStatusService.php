<?php

namespace App\Services\MailDns;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Support\MailDomain;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tenant-wide Defender DKIM Enabled/Valid from Exchange, cached per client.
 * One API list covers every accepted domain - staff do not open Defender per domain.
 */
class DkimSigningStatusService
{
    public const CACHE_PREFIX = 'entra.dkim_signing.';

    public function __construct(private MicrosoftGraphClient $graph) {}

    public function cacheKey(int $clientId): string
    {
        return self::CACHE_PREFIX.$clientId;
    }

    /**
     * @return array{
     *     available: bool,
     *     reason: ?string,
     *     fetched_at: ?string,
     *     configs: list<array{domain: string, enabled: bool, status: string, signing: bool}>,
     *     by_domain: array<string, array{domain: string, enabled: bool, status: string, signing: bool}>,
     *     primary_signing: bool,
     *     portal_custom_signing: bool,
     *     portal_custom_count: int
     * }
     */
    /**
     * Cache only - never calls Microsoft. Edit Client must stay off the live API.
     *
     * @return array<string, mixed>
     */
    public function cachedForClient(Client $client): array
    {
        $tenantId = trim((string) $client->entra_tenant_id);
        if ($tenantId === '') {
            return $this->emptySummary('no_tenant');
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (is_array($cached) && array_key_exists('available', $cached)) {
            return $this->withPortalFlags($client, $cached);
        }

        return $this->emptySummary('not_fetched');
    }

    public function summaryForClient(Client $client, bool $fresh = false): array
    {
        $tenantId = trim((string) $client->entra_tenant_id);
        if ($tenantId === '' || ! $this->graph->isConfigured()) {
            return $this->emptySummary($tenantId === '' ? 'no_tenant' : 'graph_not_configured');
        }

        if ($fresh) {
            Cache::forget($this->cacheKey($client->id));
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (is_array($cached) && array_key_exists('available', $cached)) {
            return $this->withPortalFlags($client, $cached);
        }

        try {
            $raw = $this->graph->listDkimSigningConfigs($tenantId);
        } catch (Throwable $e) {
            $raw = [
                'available' => false,
                'reason' => 'dkim_signing_request_failed',
                'configs' => [],
            ];
            report($e);
        }

        $byDomain = [];
        foreach ($raw['configs'] ?? [] as $row) {
            if (! is_array($row) || blank($row['domain'] ?? null)) {
                continue;
            }
            $byDomain[strtolower((string) $row['domain'])] = $row;
        }

        $payload = [
            'available' => (bool) ($raw['available'] ?? false),
            'reason' => $raw['reason'] ?? null,
            'fetched_at' => now()->toIso8601String(),
            'configs' => array_values($byDomain),
            'by_domain' => $byDomain,
        ];

        $minutes = $payload['available'] ? 15 : 30;
        Cache::put($this->cacheKey($client->id), $payload, now()->addMinutes($minutes));

        return $this->withPortalFlags($client, $payload);
    }

    /**
     * When Exchange answers, keep mail_dkim_signing_enabled in lockstep so step 00
     * does not need a Defender tick per domain. Does not touch the send-test tick.
     */
    public function syncChecklist(Client $client, ?array $summary = null): void
    {
        $summary ??= $this->summaryForClient($client);
        if (! ($summary['available'] ?? false)) {
            return;
        }

        $checklist = $client->onboarding_checklist ?? [];
        $signing = (bool) ($summary['portal_custom_signing'] ?? false);
        if ((bool) ($checklist['mail_dkim_signing_enabled'] ?? false) === $signing) {
            return;
        }

        $checklist['mail_dkim_signing_enabled'] = $signing;
        $client->onboarding_checklist = $checklist;
        $client->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPortalFlags(Client $client, array $payload): array
    {
        $byDomain = is_array($payload['by_domain'] ?? null) ? $payload['by_domain'] : [];
        $custom = [];
        foreach ($client->domains as $row) {
            $domain = MailDomain::normalize((string) $row->domain) ?? '';
            if ($domain === '' || str_ends_with($domain, '.onmicrosoft.com')) {
                continue;
            }
            $custom[] = $domain;
        }
        $primary = MailDomain::normalize((string) $client->primary_domain) ?? '';
        if ($primary !== '' && ! str_ends_with($primary, '.onmicrosoft.com') && ! in_array($primary, $custom, true)) {
            $custom[] = $primary;
        }

        $primarySigning = $primary !== '' && (bool) ($byDomain[$primary]['signing'] ?? false);
        $portalCustomSigning = $custom !== [] && collect($custom)->every(
            fn (string $domain): bool => (bool) ($byDomain[$domain]['signing'] ?? false)
        );

        $payload['primary_signing'] = $primarySigning;
        $payload['portal_custom_signing'] = $portalCustomSigning;
        $payload['portal_custom_count'] = count($custom);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'fetched_at' => null,
            'configs' => [],
            'by_domain' => [],
            'primary_signing' => false,
            'portal_custom_signing' => false,
            'portal_custom_count' => 0,
        ];
    }
}
