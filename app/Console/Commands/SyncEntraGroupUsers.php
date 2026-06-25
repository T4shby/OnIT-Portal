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

    protected $description = 'Sync portal users from Microsoft Entra and maintain SuperOps SCIM group membership';

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

        $hadFailure = false;

        foreach ($results as $id => $entry) {
            /** @var EntraSyncResult $result */
            $result = $entry['result'];

            $this->newLine();
            $this->info("Client: {$entry['client']} (#{$id})");

            if ($result->failed()) {
                $hadFailure = true;

                foreach ($result->errors as $error) {
                    $this->error('  '.$error);
                }

                continue;
            }

            $this->line("  Created: {$result->created}");
            $this->line("  Updated: {$result->updated}");
            $this->line("  Deactivated: {$result->deactivated}");
            $this->line("  Skipped: {$result->skipped}");

            if ($result->groupMembersAdded > 0 || $result->groupMembersRemoved > 0) {
                $this->line("  SuperOps group: +{$result->groupMembersAdded} / -{$result->groupMembersRemoved} members");
            }

            if ($result->superOpsAppUsersAssigned > 0 || $result->superOpsAppUsersRemoved > 0) {
                $this->line("  SuperOps app: +{$result->superOpsAppUsersAssigned} / -{$result->superOpsAppUsersRemoved} users");
            }

            if ($result->superOpsNameHintsUpdated > 0) {
                $this->line("  SuperOps last names updated: {$result->superOpsNameHintsUpdated}");
            }

            if ($result->superOpsUsersProvisioned > 0) {
                $this->line("  SuperOps SCIM provision triggered: {$result->superOpsUsersProvisioned}");
            }

            foreach ($result->errors as $error) {
                $hadFailure = true;
                $this->warn('  '.$error);
            }
        }

        $this->newLine();

        if ($hadFailure) {
            $this->error('Entra sync finished with errors.');

            return self::FAILURE;
        }

        $this->info('Entra sync finished.');

        return self::SUCCESS;
    }
}
