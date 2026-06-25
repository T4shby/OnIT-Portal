<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReleaseEntraSyncLockCommand extends Command
{
    protected $signature = 'portal:release-entra-sync-lock {client : Client ID}';

    protected $description = 'Force-release a stuck Entra sync lock (e.g. after nginx 504)';

    public function handle(): int
    {
        $clientId = (int) $this->argument('client');

        if ($clientId < 1) {
            $this->error('Invalid client ID.');

            return self::FAILURE;
        }

        Cache::lock('entra_sync.client.'.$clientId)->forceRelease();

        $this->info("Released Entra sync lock for client #{$clientId}.");

        return self::SUCCESS;
    }
}
