<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteApiClient;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressApiClient;
use App\Services\Huntress\HuntressClientMetricsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Live/optional smoke for Huntress + Dropsuite partner APIs.
 * Safe when disabled: reports not configured without calling the network.
 */
class ProbeSecurityApisCommand extends Command
{
    protected $signature = 'portal:probe-security-apis
        {--client= : Portal client ID (uses mapped Huntress/Dropsuite org IDs)}
        {--huntress-org= : Huntress organization ID override}
        {--dropsuite-org= : Dropsuite organization ID override}';

    protected $description = 'Probe Huntress and Dropsuite free partner APIs (mapped field map / path discovery)';

    public function handle(
        HuntressApiClient $huntressApi,
        HuntressClientMetricsService $huntressMetrics,
        DropsuiteApiClient $dropsuiteApi,
        DropsuiteClientMetricsService $dropsuiteMetrics,
    ): int {
        $client = null;
        if ($this->option('client')) {
            $client = Client::query()->find((int) $this->option('client'));
            if ($client === null) {
                $this->error('Client not found.');

                return self::FAILURE;
            }
            $this->info("Client: {$client->name} (#{$client->id})");
        }

        $this->section('Huntress');
        if (! $huntressApi->isConfigured()) {
            $this->warn('Not configured (set HUNTRESS_ENABLED + API key/secret).');
        } else {
            $orgId = (string) ($this->option('huntress-org') ?: ($client?->huntress_organization_id ?? ''));
            if ($orgId === '') {
                $this->warn('No organization ID (map clients.huntress_organization_id or pass --huntress-org).');
            } else {
                try {
                    $payload = $huntressApi->get('organizations/'.$orgId);
                    $mapped = $huntressMetrics->mapOrganizationPayload($payload);
                    $this->info("GET organizations/{$orgId} OK");
                    $this->line(json_encode($mapped, JSON_PRETTY_PRINT));
                } catch (Throwable $e) {
                    $this->error('Huntress probe failed: '.$e->getMessage());
                }
            }
        }

        $this->newLine();
        $this->section('Dropsuite');
        if (! $dropsuiteApi->isConfigured()) {
            $this->warn('Not configured (set DROPSUITE_ENABLED + URL + tokens).');
        } else {
            $orgId = (string) ($this->option('dropsuite-org') ?: ($client?->dropsuite_organization_id ?? ''));
            if ($orgId === '') {
                $this->warn('No organization ID (map clients.dropsuite_organization_id or pass --dropsuite-org).');
            } else {
                try {
                    $probe = $client ?? new Client([
                        'name' => 'probe',
                        'dropsuite_organization_id' => $orgId,
                    ]);
                    if (empty($probe->dropsuite_organization_id)) {
                        $probe->dropsuite_organization_id = $orgId;
                    }
                    if (! $probe->exists) {
                        $probe->id = 0;
                    }

                    $summary = $dropsuiteMetrics->refreshAndStore($probe);
                    $this->info('Dropsuite refresh OK');
                    $this->line(json_encode([
                        'protected_mailboxes' => $summary->protectedMailboxes,
                        'failed_backups_count' => $summary->failedBackupsCount,
                        'last_backup_status' => $summary->lastBackupStatus,
                        'available' => $summary->available,
                        'unavailable_reason' => $summary->unavailableReason,
                    ], JSON_PRETTY_PRINT));
                } catch (Throwable $e) {
                    $this->error('Dropsuite probe failed: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line("<fg=yellow;options=bold>{$title}</>");
    }
}
