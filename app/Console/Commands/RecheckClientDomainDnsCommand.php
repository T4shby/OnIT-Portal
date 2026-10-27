<?php

namespace App\Console\Commands;

use App\Jobs\CheckClientDomainDnsJob;
use App\Models\ClientDomain;
use Illuminate\Console\Command;

class RecheckClientDomainDnsCommand extends Command
{
    protected $signature = 'portal:recheck-client-domain-dns
        {--onboarding-only : Only clients with incomplete mail DNS checklist / still onboarding}
        {--client= : Limit to one client id}';

    protected $description = 'Queue DNS rechecks for client mail domains (Phase B nightly)';

    public function handle(): int
    {
        $query = ClientDomain::query()
            ->whereHas('client', function ($q): void {
                $q->where('is_active', true);
                if ($this->option('onboarding-only')) {
                    $q->where(function ($inner): void {
                        $inner->whereNull('primary_domain')
                            ->orWhere('primary_domain', '')
                            ->orWhereNull('entra_synced_at');
                    });
                }
            });

        if (filled($this->option('client'))) {
            $query->where('client_id', (int) $this->option('client'));
        }

        $ids = $query->pluck('id');
        foreach ($ids as $id) {
            CheckClientDomainDnsJob::dispatch((int) $id);
        }

        $this->info('Queued DNS recheck for '.$ids->count().' domain row(s).');

        return self::SUCCESS;
    }
}
