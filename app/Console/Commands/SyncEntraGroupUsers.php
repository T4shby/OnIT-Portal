<?php

namespace App\Console\Commands;

use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncResult;
use Illuminate\Console\Command;

class SyncEntraGroupUsers extends Command
{
    protected $signature = 'portal:sync-entra-users
                            {--client= : Sync a single client by ID}
                            {--dry-run : Show changes without writing to the database}
                            {--inline : Run synchronously in this process instead of queueing}';

    protected $description = 'Sync portal users from Microsoft Entra and maintain SuperOps SCIM group membership';

    public function handle(EntraGroupSyncService $sync): int
    {
        if (! config('services.entra_sync.enabled')) {
            $this->error('Entra sync is disabled. Set ENTRA_SYNC_ENABLED=true in .env');

            return self::FAILURE;
        }

        $clientId = $this->option('client') ? (int) $this->option('client') : null;
        $dryRun = (bool) $this->option('dry-run');
        $inline = (bool) $this->option('inline') || $dryRun || config('queue.default') === 'sync';

        if ($dryRun) {
            $this->warn('Dry run: no database changes will be made.');
        }

        if (! $inline) {
            return $this->dispatchQueued($clientId, $dryRun);
        }

        $results = $sync->syncAll($clientId, $dryRun, function (Client $client): void {
            $this->info("Syncing {$client->name} (#{$client->id})...");
        });

        if ($results === []) {
            $this->warn('No clients found with Entra sync enabled and configured.');

            return self::SUCCESS;
        }

        return $this->reportResults($results);
    }

    private function dispatchQueued(?int $clientId, bool $dryRun): int
    {
        $query = Client::query()
            ->where('is_active', true)
            ->where('entra_sync_enabled', true)
            ->whereNotNull('entra_tenant_id')
            ->orderBy('id');

        if ($clientId) {
            $query->whereKey($clientId);
        }

        $clients = $query->get(['id', 'name']);

        if ($clients->isEmpty()) {
            $this->warn('No clients found with Entra sync enabled and configured.');

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            SyncEntraClientJob::dispatchMarked($client->id, $dryRun);
            $this->info("Queued Entra sync for {$client->name} (#{$client->id}).");
        }

        $this->info("Queued {$clients->count()} Entra sync job(s). Queue worker will process them.");

        \Illuminate\Support\Facades\Cache::put(
            \App\Services\Admin\IntegrationHealthService::ENTRA_SCHEDULE_HEARTBEAT_KEY,
            ['at' => now()->toIso8601String()],
            now()->addDay(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{client: string, result: EntraSyncResult}>  $results
     */
    private function reportResults(array $results): int
    {
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
                $this->line("  SuperOps SCIM app: +{$result->superOpsAppUsersAssigned} / -{$result->superOpsAppUsersRemoved} users");
            }

            if ($result->requesterSsoUsersAssigned > 0 || $result->requesterSsoUsersRemoved > 0) {
                $this->line("  SuperOps SSO access: +{$result->requesterSsoUsersAssigned} / -{$result->requesterSsoUsersRemoved} active licensed users");
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
