<?php

namespace App\Console\Commands;

use App\Jobs\SyncClientEntraMailDomainsJob;
use App\Models\Client;
use App\Services\MailDns\EntraMailDomainSyncService;
use Illuminate\Console\Command;

class SyncEntraMailDomainsCommand extends Command
{
    protected $signature = 'portal:sync-entra-mail-domains
        {--client= : Limit to one client id}
        {--sync : Run inline instead of queueing}
        {--empty-primary-only : Only clients with no primary_domain}';

    protected $description = 'Pull Entra verified / mailbox domains into portal mail domains (Phase B)';

    public function handle(EntraMailDomainSyncService $sync): int
    {
        $query = Client::query()
            ->where('is_active', true)
            ->whereNotNull('entra_tenant_id')
            ->where('entra_tenant_id', '!=', '');

        if (filled($this->option('client'))) {
            $query->where('id', (int) $this->option('client'));
        }

        if ($this->option('empty-primary-only')) {
            $query->where(function ($q): void {
                $q->whereNull('primary_domain')->orWhere('primary_domain', '');
            });
        }

        $clients = $query->orderBy('id')->get();
        if ($clients->isEmpty()) {
            $this->warn('No matching clients with an Entra tenant ID.');

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            if ($this->option('sync')) {
                $result = $sync->sync($client);
                $this->line("#{$client->id} {$client->name}: ".$result['summary']);
            } else {
                SyncClientEntraMailDomainsJob::dispatch($client->id);
                $this->line("#{$client->id} {$client->name}: queued");
            }
        }

        return self::SUCCESS;
    }
}
