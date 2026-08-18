<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\EntraSync\SuperOpsScimRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RepairSuperOpsScimCommand extends Command
{
    protected $signature = 'portal:repair-superops-scim
                            {--client= : Repair a single client by ID}
                            {--check : Report SCIM health only - no changes}
                            {--provision-missing : Provision-on-demand portal users missing from SuperOps}
                            {--sync : Queue portal Entra sync after repair}';

    protected $description = 'Repair missing/stopped Entra SCIM provisioning jobs for SuperOps (Sync 1)';

    public function handle(
        MicrosoftGraphClient $graph,
        SuperOpsScimRepairService $repair,
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
                $this->error('  Cannot repair without Apply SCIM - paste SuperOps Tenant URL + Secret Token on Edit Client.');

                continue;
            }

            if ($health['ok'] && ! $provisionMissing) {
                $this->line('  Nothing to repair (use --provision-missing to push users missing from SuperOps).');

                continue;
            }

            if ($health['needsRepair'] || $provisionMissing || ! $health['ok']) {
                $result = $repair->retryExport(
                    $client,
                    provisionMissing: $provisionMissing,
                    queueSync: $queueSync,
                );

                foreach ($result['repair']['details'] ?? [] as $detail) {
                    $this->line('  '.$detail);
                }

                foreach ($result['blockers'] as $blocker) {
                    $this->warn('  '.$blocker);
                }

                $this->line('  '.$result['message']);

                if ($result['provisioned'] > 0) {
                    $this->info("  Provision-on-demand: {$result['provisioned']} user(s)");
                }

                Cache::forget('scim.health.'.$client->id);

                if (! $result['ok']) {
                    $hadFailure = true;
                }
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
}
