<?php

namespace App\Console\Commands;

use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncResult;
use Illuminate\Console\Command;

class SyncEntraGroupUsers extends Command
{
    protected $signature = 'portal:sync-entra-users
                            {--client= : Sync a single client by ID}
                            {--dry-run : Show changes without writing to the database}';

    protected $description = 'Sync portal users from Microsoft Entra security group membership';

    public function handle(EntraGroupSyncService $sync): int
    {
        if (! config('services.entra_sync.enabled')) {
            $this->error('Entra sync is disabled. Set ENTRA_SYNC_ENABLED=true in .env');

            return self::FAILURE;
        }

        $clientId = $this->option('client') ? (int) $this->option('client') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no database changes will be made.');
        }

        $results = $sync->syncAll($clientId, $dryRun);

        if ($results === []) {
            $this->warn('No clients found with Entra sync enabled and configured.');

            return self::SUCCESS;
        }

        foreach ($results as $id => $entry) {
            /** @var EntraSyncResult $result */
            $result = $entry['result'];

            $this->newLine();
            $this->info("Client: {$entry['client']} (#{$id})");

            if ($result->hasErrors() && $result->totalChanged() === 0 && $result->skipped === 0) {
                foreach ($result->errors as $error) {
                    $this->error('  '.$error);
                }

                continue;
            }

            $this->line("  Created: {$result->created}");
            $this->line("  Updated: {$result->updated}");
            $this->line("  Deactivated: {$result->deactivated}");
            $this->line("  Skipped: {$result->skipped}");

            foreach ($result->errors as $error) {
                $this->warn('  '.$error);
            }
        }

        $this->newLine();
        $this->info('Entra sync finished.');

        return self::SUCCESS;
    }
}
