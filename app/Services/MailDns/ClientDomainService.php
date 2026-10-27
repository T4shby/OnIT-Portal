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

    public function checkNow(ClientDomain $domain, bool $sync = false): ClientDomain
    {
        if ($sync) {
            return $this->dns->refresh($domain);
        }

        CheckClientDomainDnsJob::dispatch($domain->id);

        return $domain;
    }

    /**
     * Checklist Done when primary exists and last check has no red (yellow OK),
     * or every domain that has been checked is non-red.
     */
    public function dnsChecklistComplete(Client $client): bool
    {
        if (! filled($client->primary_domain)) {
            return false;
        }

        $domains = ClientDomain::query()->where('client_id', $client->id)->get();
        if ($domains->isEmpty()) {
            return false;
        }

        $primary = $domains->firstWhere('is_primary', true) ?? $domains->firstWhere('domain', $client->primary_domain);
        if ($primary === null || $primary->last_checked_at === null) {
            return false;
        }

        return $primary->overallBand() !== 'red';
    }

    public function anyDomainStillRed(Client $client): bool
    {
        $domains = ClientDomain::query()->where('client_id', $client->id)->get();
        if ($domains->isEmpty()) {
            return filled($client->primary_domain);
        }

        foreach ($domains as $domain) {
            if ($domain->hasRedChecks()) {
                return true;
            }
        }

        return false;
    }
}
