<?php

namespace App\Services\MailDns;

use App\Jobs\CheckClientDomainDnsJob;
use App\Models\Client;
use App\Models\ClientDomain;
use App\Support\MailDomain;
use Illuminate\Support\Facades\DB;

class ClientDomainService
{
    public function __construct(private MailDnsHealthService $dns) {}

    public function setPrimaryDomain(
        Client $client,
        string $domain,
        bool $queueCheck = true,
        string $source = 'manual',
    ): ClientDomain {
        $domain = MailDomain::requireValid($domain, 'primary_domain');
        $source = $this->normaliseSource($source);

        return DB::transaction(function () use ($client, $domain, $queueCheck, $source): ClientDomain {
            $client->forceFill(['primary_domain' => $domain])->save();

            ClientDomain::query()
                ->where('client_id', $client->id)
                ->where('is_primary', true)
                ->where('domain', '!=', $domain)
                ->update(['is_primary' => false]);

            $row = ClientDomain::query()->updateOrCreate(
                [
                    'client_id' => $client->id,
                    'domain' => $domain,
                ],
                [
                    'is_primary' => true,
                    'source' => $source,
                ],
            );

            if (! $row->is_primary) {
                $row->forceFill(['is_primary' => true])->save();
            }

            if ($queueCheck) {
                CheckClientDomainDnsJob::dispatch($row->id);
            }

            return $row->refresh();
        });
    }

    public function addDomain(
        Client $client,
        string $domain,
        bool $makePrimary = false,
        array $dkimSelectors = [],
        string $source = 'manual',
    ): ClientDomain {
        $domain = MailDomain::requireValid($domain, 'domain');
        $source = $this->normaliseSource($source);

        return DB::transaction(function () use ($client, $domain, $makePrimary, $dkimSelectors, $source): ClientDomain {
            if ($makePrimary) {
                return $this->setPrimaryDomain($client, $domain, true, $source);
            }

            $row = ClientDomain::query()->firstOrCreate(
                [
                    'client_id' => $client->id,
                    'domain' => $domain,
                ],
                [
                    'is_primary' => false,
                    'source' => $source,
                    'dkim_selectors' => $dkimSelectors === [] ? null : array_values($dkimSelectors),
                ],
            );

            if ($row->wasRecentlyCreated === false && $row->source !== $source && in_array($source, ['entra', 'entra_mail'], true)) {
                $row->forceFill(['source' => $source])->save();
            }

            CheckClientDomainDnsJob::dispatch($row->id);

            return $row->refresh();
        });
    }

    private function normaliseSource(string $source): string
    {
        return in_array($source, ['manual', 'entra', 'entra_mail'], true) ? $source : 'manual';
    }

    public function removeDomain(ClientDomain $domain): void
    {
        if ($domain->is_primary) {
            abort(422, 'Set another primary domain before removing this one.');
        }

        $domain->delete();
    }

    public function checkNow(
        ClientDomain $domain,
        bool $sync = false,
        ?int $userId = null,
        string $source = 'manual',
    ): ClientDomain {
        if ($sync) {
            return $this->dns->refresh($domain, userId: $userId, source: $source);
        }

        CheckClientDomainDnsJob::dispatch($domain->id);

        return $domain;
    }

    /**
     * Core DNS Done when primary exists and last check has no red (yellow OK).
     * Does not prove M365 DKIM signing is Enabled - see emailAuthVerification*.
     */
    public function dnsChecklistComplete(Client $client): bool
    {
        $primary = $this->primaryDomain($client);
        if ($primary === null || $primary->last_checked_at === null) {
            return false;
        }

        return $primary->overallBand() !== 'red';
    }

    /**
     * M365 (and Entra-linked) customers need Defender DKIM signing + a real send test.
     * Public DNS can show green selector CNAMEs while signing is still off (NoDKIMKeys).
     */
    public function emailAuthVerificationRequired(Client $client): bool
    {
        if (! filled($client->primary_domain)) {
            return false;
        }

        if (filled($client->entra_tenant_id)) {
            return true;
        }

        $primary = $this->primaryDomain($client);
        if ($primary === null) {
            return false;
        }

        $result = is_array($primary->last_result) ? $primary->last_result : [];
        $providerKey = (string) ($result['provider']['key'] ?? '');
        if ($providerKey === 'm365') {
            return true;
        }

        $found = $result['checks']['dkim']['selectors_found'] ?? [];
        if (! is_array($found)) {
            return false;
        }

        return (bool) array_intersect(['selector1', 'selector2'], array_map('strval', $found));
    }

    public function emailAuthVerificationComplete(Client $client): bool
    {
        if (! $this->emailAuthVerificationRequired($client)) {
            return true;
        }

        $checklist = $client->onboarding_checklist ?? [];

        return (bool) ($checklist['mail_dkim_signing_enabled'] ?? false)
            && (bool) ($checklist['mail_auth_test_passed'] ?? false);
    }

    public function primaryDomain(Client $client): ?ClientDomain
    {
        if (! filled($client->primary_domain)) {
            return null;
        }

        $domains = ClientDomain::query()->where('client_id', $client->id)->get();
        if ($domains->isEmpty()) {
            return null;
        }

        return $domains->firstWhere('is_primary', true)
            ?? $domains->firstWhere('domain', $client->primary_domain);
    }

    public function anyDomainStillRed(Client $client): bool
    {
        $domains = ClientDomain::query()->where('client_id', $client->id)->get();
        // No DNS rows yet (or only unchecked) is not "still red" - that is pending.
        if ($domains->isEmpty()) {
            return false;
        }

        foreach ($domains as $domain) {
            if ($domain->hasRedChecks()) {
                return true;
            }
        }

        return false;
    }
}
