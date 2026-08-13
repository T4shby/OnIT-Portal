<?php

namespace App\Console\Commands;

use App\Enums\UserProvisionSource;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RepairSuperOpsScimCommand extends Command
{
    protected $signature = 'portal:repair-superops-scim
                            {--client= : Repair a single client by ID}
                            {--check : Report SCIM health only — no changes}
                            {--provision-missing : Provision-on-demand portal users missing from SuperOps}
                            {--sync : Queue portal Entra sync after repair}';

    protected $description = 'Repair missing/stopped Entra SCIM provisioning jobs for SuperOps (Sync 1)';

    public function handle(
        MicrosoftGraphClient $graph,
        SuperOpsUserSyncService $superOpsUsers,
    ): int {
        if (! config('services.entra_sync.enabled')) {
            $this->error('Entra sync is disabled. Set ENTRA_SYNC_ENABLED=true in .env');

            return self::FAILURE;
        }

        $clientId = $this->option('client') ? (int) $this->option('client') : null;
        $checkOnly = (bool) $this->option('check');
        $provisionMissing = (bool) $this->option('provision-missing');
        $queueSync = (bool) $this->option('sync');

        $query = Client::query()
            ->where('is_active', true)
            ->where('entra_sync_enabled', true)
            ->whereNotNull('entra_tenant_id')
            ->whereNotNull('entra_superops_app_id')
            ->orderBy('id');

        if ($clientId) {
            $query->whereKey($clientId);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->warn('No clients found with Entra sync + SuperOps SCIM app configured.');

            return self::SUCCESS;
        }

        $hadFailure = false;

        foreach ($clients as $client) {
            $this->newLine();
            $this->info("Client: {$client->name} (#{$client->id})");

            $health = $graph->getSuperOpsScimProvisioningHealth(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );

            if ($health['error']) {
                $hadFailure = true;
                $this->error('  '.$health['error']);

                continue;
            }

            foreach ($health['warnings'] as $warning) {
                $this->warn('  '.$warning);
            }

            foreach ($health['details'] as $detail) {
                $this->line('  '.$detail);
            }

            if ($health['ok']) {
                $this->info('  SCIM health: OK');
            } else {
                $this->warn('  SCIM health: needs attention');
            }

            if ($checkOnly) {
                continue;
            }

            if ($health['needsApplyScim']) {
                $hadFailure = true;
                $this->error('  Cannot repair without Apply SCIM — paste SuperOps Tenant URL + Secret Token on Edit Client.');

                continue;
            }

            if (! $health['needsRepair'] && ! $provisionMissing) {
                $this->line('  Nothing to repair (use --provision-missing to push users missing from SuperOps).');

                continue;
            }

            if ($health['needsRepair']) {
                try {
                    $result = $graph->repairSuperOpsScimProvisioning(
                        (string) $client->entra_tenant_id,
                        (string) $client->entra_superops_app_id,
                    );

                    foreach ($result['details'] as $detail) {
                        $this->line('  '.$detail);
                    }

                    foreach ($result['warnings'] as $warning) {
                        $this->warn('  '.$warning);
                    }

                    $this->info('  SCIM job repaired: '.$result['jobId']);
                    Cache::forget('scim.health.'.$client->id);
                } catch (\Throwable $e) {
                    $hadFailure = true;
                    $this->error('  Repair failed: '.$e->getMessage());

                    continue;
                }
            }

            if ($provisionMissing) {
                $hadFailure = $this->provisionMissingUsers($client, $graph, $superOpsUsers) || $hadFailure;
            }

            if ($queueSync) {
                SyncEntraClientJob::dispatchMarked($client->id, false);
                $this->info('  Queued portal Entra sync.');
            }
        }

        $this->newLine();

        if ($hadFailure) {
            $this->error('SuperOps SCIM repair finished with errors.');

            return self::FAILURE;
        }

        $this->info('SuperOps SCIM repair finished.');

        return self::SUCCESS;
    }

    private function provisionMissingUsers(
        Client $client,
        MicrosoftGraphClient $graph,
        SuperOpsUserSyncService $superOpsUsers,
    ): bool {
        if (! config('services.entra_sync.superops_provision_on_demand')) {
            $this->warn('  --provision-missing skipped: ENTRA_SYNC_SUPEROPS_PROVISION_ON_DEMAND is false.');

            return false;
        }

        $requesterEmails = $superOpsUsers->listRequesterEmails($client);
        $requesterEmailSet = array_fill_keys($requesterEmails, true);

        $missingUsers = User::query()
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->where('provisioned_by', UserProvisionSource::EntraSync)
            ->whereNotNull('entra_object_id')
            ->whereNotNull('email')
            ->get()
            ->filter(static function (User $user) use ($requesterEmailSet): bool {
                $email = strtolower(trim((string) $user->email));

                return $email !== '' && ! isset($requesterEmailSet[$email]);
            });

        if ($missingUsers->isEmpty()) {
            $this->line('  No portal users missing from SuperOps requester list.');

            return false;
        }

        $objectIds = $missingUsers
            ->pluck('entra_object_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $this->info('  Provision-on-demand for '.$missingUsers->count().' user(s) missing from SuperOps…');

        try {
            $servicePrincipalId = $graph->resolveEnterpriseServicePrincipalId(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
            $context = $graph->resolveSuperOpsScimProvisioningContext(
                (string) $client->entra_tenant_id,
                $servicePrincipalId,
            );
            $provisioned = $graph->provisionUsersOnDemand(
                (string) $client->entra_tenant_id,
                $servicePrincipalId,
                $context['jobId'],
                $context['userRuleId'],
                $objectIds,
            );

            $this->info("  Provision-on-demand accepted for {$provisioned} user(s). Check Entra Provisioning logs in 1–2 minutes.");

            return false;
        } catch (\Throwable $e) {
            $this->error('  Provision-on-demand failed: '.$e->getMessage());

            return true;
        }
    }
}
