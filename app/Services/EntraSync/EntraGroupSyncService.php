<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\ExternalServicesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EntraGroupSyncService
{
    public function __construct(
        private MicrosoftGraphClient $graph,
        private ExternalServicesService $portalLinks,
    ) {}

    public function syncClient(Client $client, bool $dryRun = false): EntraSyncResult
    {
        if (! $client->hasEntraSyncConfigured()) {
            return new EntraSyncResult(errors: ['Client does not have Entra sync configured.']);
        }

        if (! config('services.entra_sync.enabled')) {
            return new EntraSyncResult(errors: ['Entra sync is disabled (ENTRA_SYNC_ENABLED=false).']);
        }

        if (! $this->graph->isConfigured()) {
            return new EntraSyncResult(errors: ['Microsoft Graph credentials are not configured.']);
        }

        try {
            $graphUsers = $this->graph->listSyncEligibleUsers($client->entra_tenant_id);
        } catch (Throwable $e) {
            Log::error('Entra tenant sync failed to read Graph', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return new EntraSyncResult(errors: [$e->getMessage()]);
        }

        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $skipped = 0;
        $groupMembersAdded = 0;
        $groupMembersRemoved = 0;
        $errors = [];
        $activeObjectIds = [];
        $activeEmails = [];
        $desiredGroupMemberIds = array_map(
            static fn (array $graphUser): string => $graphUser['id'],
            $graphUsers,
        );

        foreach ($graphUsers as $graphUser) {
            $email = $this->resolveEmail($graphUser);

            if ($email === null) {
                $skipped++;
                $errors[] = 'Skipped Graph user '.$graphUser['id'].' (no mail or userPrincipalName).';

                continue;
            }

            $identityType = $graphUser['identityType'];
            $activeObjectIds[] = $graphUser['id'];
            $activeEmails[] = $email;

            [$shouldBeActive, $portalLoginEnabled] = $this->resolveAccountFlags(
                $identityType,
                $graphUser['accountEnabled'],
            );

            $name = EntraSyncDisplayName::format(
                $graphUser['displayName'],
                $identityType,
                $email,
            );

            $existing = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($existing && $existing->client_id !== $client->id) {
                $skipped++;
                $errors[] = "Email {$email} already belongs to another client (user #{$existing->id}).";

                continue;
            }

            if ($existing && in_array($existing->role, [UserRole::SuperAdmin, UserRole::AccountManager], true)) {
                $skipped++;
                $errors[] = "Skipped admin account {$email}.";

                continue;
            }

            if ($existing && $existing->provisioned_by === UserProvisionSource::Manual) {
                $skipped++;
                $errors[] = "Skipped manual user {$email}.";

                continue;
            }

            if ($dryRun) {
                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }

                continue;
            }

            $attributes = [
                'name' => $name,
                'entra_object_id' => $graphUser['id'],
                'entra_identity_type' => $identityType,
                'is_active' => $shouldBeActive,
                'portal_login_enabled' => $portalLoginEnabled,
                'provisioned_by' => UserProvisionSource::EntraSync,
                'entra_synced_at' => now(),
            ];

            if ($existing) {
                $existing->update($attributes);
                $updated++;
            } else {
                User::create(array_merge($attributes, [
                    'client_id' => $client->id,
                    'email' => $email,
                    'role' => UserRole::ClientUser,
                ]));
                $created++;
            }
        }

        $toDeactivate = $this->usersRemovedFromScope($client, $activeObjectIds, $activeEmails);
        $deactivated = $toDeactivate->count();

        if (! $dryRun) {
            foreach ($toDeactivate as $user) {
                $user->update([
                    'is_active' => false,
                    'portal_login_enabled' => false,
                    'entra_synced_at' => now(),
                ]);
            }

            $client->update(['entra_synced_at' => now()]);
            $this->portalLinks->clearCache($client->id);
        }

        if ($this->shouldMaintainSuperOpsGroup($client)) {
            [$groupMembersAdded, $groupMembersRemoved, $groupErrors] = $this->syncSuperOpsGroupMembership(
                $client,
                $desiredGroupMemberIds,
                $dryRun,
            );
            $errors = array_merge($errors, $groupErrors);
        }

        return new EntraSyncResult(
            $created,
            $updated,
            $deactivated,
            $skipped,
            $groupMembersAdded,
            $groupMembersRemoved,
            $errors,
        );
    }

    private function shouldMaintainSuperOpsGroup(Client $client): bool
    {
        return config('services.entra_sync.maintain_superops_group')
            && filled($client->entra_group_id);
    }

    /**
     * Keep the customer's SuperOps SCIM security group aligned with licensed users + shared mailboxes.
     *
     * @param  list<string>  $desiredMemberIds
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function syncSuperOpsGroupMembership(Client $client, array $desiredMemberIds, bool $dryRun): array
    {
        $tenantId = (string) $client->entra_tenant_id;
        $groupId = (string) $client->entra_group_id;

        try {
            $currentMemberIds = $this->graph->listGroupMemberUserIds($tenantId, $groupId);
        } catch (Throwable $e) {
            Log::error('Entra SuperOps group membership read failed', [
                'client_id' => $client->id,
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return [0, 0, ['SuperOps group sync failed: '.$e->getMessage()]];
        }

        $desired = array_values(array_unique($desiredMemberIds));
        $current = array_values(array_unique($currentMemberIds));

        $toAdd = array_values(array_diff($desired, $current));
        $toRemove = array_values(array_diff($current, $desired));

        if ($dryRun) {
            return [count($toAdd), count($toRemove), []];
        }

        $added = 0;
        $removed = 0;
        $errors = [];

        foreach ($toAdd as $userId) {
            try {
                $this->graph->addGroupMember($tenantId, $groupId, $userId);
                $added++;
            } catch (Throwable $e) {
                $errors[] = "Failed to add {$userId} to SuperOps group: {$e->getMessage()}";
            }
        }

        foreach ($toRemove as $userId) {
            try {
                $this->graph->removeGroupMember($tenantId, $groupId, $userId);
                $removed++;
            } catch (Throwable $e) {
                $errors[] = "Failed to remove {$userId} from SuperOps group: {$e->getMessage()}";
            }
        }

        return [$added, $removed, $errors];
    }

    /**
     * @return array{0: bool, 1: bool} [is_active, portal_login_enabled]
     */
    private function resolveAccountFlags(EntraIdentityType $identityType, bool $accountEnabled): array
    {
        if ($identityType === EntraIdentityType::SharedMailbox) {
            return [true, false];
        }

        return [$accountEnabled, $accountEnabled];
    }

    /**
     * @param  list<string>  $activeObjectIds
     * @param  list<string>  $activeEmails
     * @return Collection<int, User>
     */
    private function usersRemovedFromScope(Client $client, array $activeObjectIds, array $activeEmails): Collection
    {
        return User::query()
            ->where('client_id', $client->id)
            ->where('provisioned_by', UserProvisionSource::EntraSync)
            ->where('is_active', true)
            ->get()
            ->filter(function (User $user) use ($activeObjectIds, $activeEmails) {
                return ! in_array($user->entra_object_id, $activeObjectIds, true)
                    && ! in_array(strtolower($user->email), $activeEmails, true);
            });
    }

    /**
     * @param  array{id: string, mail: ?string, userPrincipalName: ?string}  $graphUser
     */
    private function resolveEmail(array $graphUser): ?string
    {
        $email = $graphUser['mail'] ?: $graphUser['userPrincipalName'];

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return strtolower($email);
    }

    public function syncAll(?int $clientId = null, bool $dryRun = false): array
    {
        $query = Client::query()
            ->where('is_active', true)
            ->where('entra_sync_enabled', true)
            ->whereNotNull('entra_tenant_id');

        if ($clientId) {
            $query->whereKey($clientId);
        }

        $results = [];

        foreach ($query->get() as $client) {
            $results[$client->id] = [
                'client' => $client->name,
                'result' => $this->syncClient($client, $dryRun),
            ];
        }

        return $results;
    }
}
