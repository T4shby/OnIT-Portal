<?php

namespace App\Services\EntraSync;

use App\Enums\UserProvisionSource;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry Sync 1 (Entra SCIM export) when Apply SCIM stopped mid-flight — no secret re-paste.
 */
class SuperOpsScimRepairService
{
    public function __construct(
        private MicrosoftGraphClient $graph,
        private SuperOpsUserSyncService $superOpsUsers,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     health: array<string, mixed>|null,
     *     repair: array<string, mixed>|null,
     *     provisioned: int,
     *     sync_queued: bool,
     *     blockers: list<string>
     * }
     */
    public function retryExport(Client $client, bool $provisionMissing = true, bool $queueSync = true): array
    {
        $blockers = [];

        if (! config('services.entra_sync.enabled')) {
            return $this->result(false, 'Entra sync is disabled on this server.', blockers: ['ENTRA_SYNC_ENABLED=false']);
        }

        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_app_id)) {
            return $this->result(false, 'Connect Microsoft first — missing tenant or SuperOps SCIM app ID.', blockers: [
                'Run Connect Microsoft / Retry Graph setup before retrying SCIM export.',
            ]);
        }

        Cache::forget('scim.health.'.$client->id);

        $health = $this->graph->getSuperOpsScimProvisioningHealth(
            (string) $client->entra_tenant_id,
            (string) $client->entra_superops_app_id,
        );

        if ($health['needsApplyScim'] ?? false) {
            return $this->result(
                false,
                'SuperOps SCIM credentials are not in Entra yet — use Apply SCIM once with Tenant URL + Secret Token.',
                $health,
                blockers: ['Apply SCIM required — no stored BaseAddress in Entra.'],
            );
        }

        if ($health['ok'] ?? false) {
            $provisioned = 0;
            if ($provisionMissing) {
                $provisioned = $this->provisionMissingUsers($client, $blockers);
            }
            if ($queueSync) {
                SyncEntraClientJob::dispatchMarked($client->id, false);
            }

            return $this->result(
                true,
                'SCIM export already active.'.($queueSync ? ' Portal sync queued.' : ''),
                $health,
                provisioned: $provisioned,
                syncQueued: $queueSync,
            );
        }

        $repair = null;
        try {
            $repair = $this->graph->repairSuperOpsScimProvisioning(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
        } catch (Throwable $e) {
            Log::warning('SuperOps SCIM export retry failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return $this->result(
                false,
                'SCIM export retry failed: '.$e->getMessage(),
                $health,
                blockers: [$e->getMessage()],
            );
        }

        Cache::forget('scim.health.'.$client->id);
        $health = $this->graph->getSuperOpsScimProvisioningHealth(
            (string) $client->entra_tenant_id,
            (string) $client->entra_superops_app_id,
        );

        foreach ($repair['warnings'] ?? [] as $warning) {
            $blockers[] = (string) $warning;
        }

        if (! ($health['ok'] ?? false) && ! ($health['hasProvisioningJob'] ?? false)) {
            $blockers[] = 'Graph still reports no listable SCIM job — portal will auto-retry; if this persists, re-Apply SCIM with the same tokens.';

            return $this->result(
                false,
                'SCIM repair ran but export is not healthy yet. Retry again in one minute.',
                $health,
                $repair,
                blockers: $blockers,
            );
        }

        $provisioned = 0;
        if ($provisionMissing) {
            $provisioned = $this->provisionMissingUsers($client, $blockers);
        }

        if ($queueSync) {
            SyncEntraClientJob::dispatchMarked($client->id, false);
        }

        $mappingOk = (bool) ($repair['nameMappingsConfigured'] ?? false);
        if (! $mappingOk) {
            $blockers[] = 'Name mappings not confirmed — retry once more or re-Apply SCIM.';
        }

        return $this->result(
            ($health['ok'] ?? false) && $mappingOk,
            ($health['ok'] ?? false)
                ? 'SCIM export restarted.'.($queueSync ? ' Portal sync queued.' : '')
                : 'SCIM job recreated — waiting for Entra to mark export active.',
            $health,
            $repair,
            provisioned: $provisioned,
            syncQueued: $queueSync,
            blockers: $blockers,
        );
    }

    /**
     * @param  list<string>  $blockers
     */
    private function provisionMissingUsers(Client $client, array &$blockers): int
    {
        if (! config('services.entra_sync.superops_provision_on_demand')) {
            return 0;
        }

        $requesterEmails = $this->superOpsUsers->listRequesterEmails($client);
        $requesterEmailSet = array_fill_keys($requesterEmails, true);

        $objectIds = User::query()
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->where('provisioned_by', UserProvisionSource::EntraSync)
            ->whereNotNull('entra_object_id')
            ->whereNotNull('email')
            ->get()
            ->filter(static function (User $user) use ($requesterEmailSet): bool {
                $email = strtolower(trim((string) $user->email));

                return $email !== '' && ! isset($requesterEmailSet[$email]);
            })
            ->pluck('entra_object_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($objectIds === []) {
            return 0;
        }

        try {
            $servicePrincipalId = $this->graph->resolveEnterpriseServicePrincipalId(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
            $context = $this->graph->resolveSuperOpsScimProvisioningContext(
                (string) $client->entra_tenant_id,
                $servicePrincipalId,
            );

            return $this->graph->provisionUsersOnDemand(
                (string) $client->entra_tenant_id,
                $servicePrincipalId,
                $context['jobId'],
                $context['userRuleId'],
                $objectIds,
            );
        } catch (Throwable $e) {
            $blockers[] = 'Provision-on-demand: '.$e->getMessage();

            return 0;
        }
    }

    /**
     * @param  list<string>  $blockers
     * @return array{
     *     ok: bool,
     *     message: string,
     *     health: array<string, mixed>|null,
     *     repair: array<string, mixed>|null,
     *     provisioned: int,
     *     sync_queued: bool,
     *     blockers: list<string>
     * }
     */
    private function result(
        bool $ok,
        string $message,
        ?array $health = null,
        ?array $repair = null,
        int $provisioned = 0,
        bool $syncQueued = false,
        array $blockers = [],
    ): array {
        return [
            'ok' => $ok,
            'message' => $message,
            'health' => $health,
            'repair' => $repair,
            'provisioned' => $provisioned,
            'sync_queued' => $syncQueued,
            'blockers' => array_values(array_unique(array_filter($blockers))),
        ];
    }
}
