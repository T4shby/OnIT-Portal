<?php

namespace App\Console\Commands;

use App\Jobs\AuditGdapRelationshipsJob;
use Illuminate\Console\Command;

class AuditGdapRelationshipsCommand extends Command
{
    protected $signature = 'portal:audit-gdap
        {--client= : Limit to one client id}';

    protected $description = 'Queue a GDAP audit (create, assign, auto-extend) for active portal clients';

    public function handle(): int
    {
        if (! config('services.gdap.enabled')) {
            $this->info('GDAP audit is off (GDAP_ENABLED=false).');

            return self::SUCCESS;
        }

        $clientId = filled($this->option('client')) ? (int) $this->option('client') : null;
        AuditGdapRelationshipsJob::dispatch($clientId);

        $this->info($clientId === null
            ? 'Queued a GDAP audit for every active client.'
            : 'Queued a GDAP audit for client '.$clientId.'.');

        return self::SUCCESS;
    }
}
