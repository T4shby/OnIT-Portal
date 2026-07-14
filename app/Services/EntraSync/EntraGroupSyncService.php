<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\ExternalServicesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        $lock = null;

        if (! $dryRun) {
            $lock = Cache::lock(
                'entra_sync.client.'.$client->id,
                (int) config('services.entra_sync.lock_seconds', 600),
            );

            if (! $lock->get()) {
                return new EntraSyncResult(errors: [
                    'Entra sync is already running for this client. Wait for it to finish and try again.',
                ]);
            }
        }

        if (! app()->runningInConsole()) {
            set_time_limit((int) config('services.entra_sync.web_max_execution_seconds', 300));
        }

        try {
            return $this->performSyncClient($client, $dryRun);
        } finally {
            $lock?->release();
        }
    }

    private function performSyncClient(Client $client, bool $dryRun): EntraSyncResult
    {
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
        $superOpsAppUsersAssigned = 0;
        $superOpsAppUsersRemoved = 0;
        $requesterSsoUsersAssigned = 0;
        $requesterSsoUsersRemoved = 0;
        $superOpsNameHintsUpdated = 0;
        $errors = [];
        $activeObjectIds = [];
        $activeEmails = [];
        $desiredGroupMemberIds = [];
        $desiredSuperOpsAppUserIds = [];
        $desiredRequesterSsoUserIds = [];

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
            $desiredGroupMemberIds[] = $graphUser['id'];

            if ($this->shouldAssignToSuperOpsApp($identityType, $graphUser['accountEnabled'])) {
                $desiredSuperOpsAppUserIds[] = $graphUser['id'];
            }

            if ($identityType === EntraIdentityType::User && $graphUser['accountEnabled']) {
                $desiredRequesterSsoUserIds[] = $graphUser['id'];
            }

            [$shouldBeActive, $portalLoginEnabled] = $this->resolveAccountFlags(
                $identityType,
                $graphUser['accountEnabled'],
            );

            $name = EntraSyncDisplayName::baseName($graphUser['displayName'], $email);

            if ($this->shouldSetSuperOpsNameHint()) {
                $superOpsFamilyName = EntraSyncDisplayName::formatSuperOpsFamilyName(
                    $graphUser['surname'] ?? null,
                    $graphUser['givenName'] ?? null,
                    $graphUser['displayName'],
                    $identityType,
                    $email,
                );

                if ($dryRun) {
                    $superOpsNameHintsUpdated++;
                } else {
                    try {
                        $this->graph->setSuperOpsNameExtensionAttribute(
                            (string) $client->entra_tenant_id,
                            $graphUser['id'],
                            $superOpsFamilyName,
                        );
                        $superOpsNameHintsUpdated++;
                    } catch (Throwable $e) {
                        $errors[] = "Failed to set SuperOps last name for {$email}: {$e->getMessage()}";
                    }
                }
            }

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
        }

        if ($this->shouldMaintainSuperOpsGroup($client)) {
            [$groupMembersAdded, $groupMembersRemoved, $groupErrors] = $this->syncSuperOpsGroupMembership(
                $client,
                $desiredGroupMemberIds,
                $dryRun,
            );
            $errors = array_merge($errors, $groupErrors);
        }

        if ($this->shouldMaintainSuperOpsAppUsers($client)) {
            [$superOpsAppUsersAssigned, $superOpsAppUsersRemoved, $appErrors] = $this->syncSuperOpsAppUserAssignments(
                $client,
                $desiredSuperOpsAppUserIds,
                $dryRun,
            );
            $errors = array_merge($errors, $appErrors);
        }

        if ($this->shouldMaintainRequesterSsoUsers($client)) {
            [$requesterSsoUsersAssigned, $requesterSsoUsersRemoved, $ssoErrors] = $this->syncRequesterSsoUserAssignments(
                $client,
                $desiredRequesterSsoUserIds,
                $dryRun,
            );
            $errors = array_merge($errors, $ssoErrors);
        }

        $superOpsUsersProvisioned = 0;

        if (! $dryRun && $this->shouldTriggerSuperOpsScimProvision($client)) {
            if ($superOpsNameHintsUpdated > 0) {
                $delaySeconds = max(0, (int) config('services.entra_sync.superops_provision_delay_after_names_seconds', 3));

                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }

            [$superOpsUsersProvisioned, $provisionErrors] = $this->triggerSuperOpsScimProvision(
                $client,
                $desiredSuperOpsAppUserIds,
            );
            $errors = array_merge($errors, $provisionErrors);
        }

        if (! $dryRun) {
            $client->update(['entra_synced_at' => now()]);
            $this->portalLinks->clearCache($client->id);
        }

        return new EntraSyncResult(
            created: $created,
            updated: $updated,
            deactivated: $deactivated,
            skipped: $skipped,
            groupMembersAdded: $groupMembersAdded,
            groupMembersRemoved: $groupMembersRemoved,
            superOpsAppUsersAssigned: $superOpsAppUsersAssigned,
            superOpsAppUsersRemoved: $superOpsAppUsersRemoved,
            requesterSsoUsersAssigned: $requesterSsoUsersAssigned,
            requesterSsoUsersRemoved: $requesterSsoUsersRemoved,
            superOpsNameHintsUpdated: $superOpsNameHintsUpdated,
            superOpsUsersProvisioned: $superOpsUsersProvisioned,
            errors: $errors,
        );
    }

    /**
     * Strip mistaken (User Mailbox) / (Shared Mailbox) suffixes from Entra displayName in the customer tenant.
     *
     * @return array{reverted: int, errors: list<string>}
     */
    public function revertEntraDisplayNames(Client $client, bool $dryRun = false): array
    {
        if (! $client->hasEntraSyncConfigured()) {
            return ['reverted' => 0, 'errors' => ['Client does not have Entra sync configured.']];
        }

        if (! $this->graph->isConfigured()) {
            return ['reverted' => 0, 'errors' => ['Microsoft Graph credentials are not configured.']];
        }

        $tenantId = (string) $client->entra_tenant_id;
        $reverted = 0;
        $errors = [];

        try {
            $graphUsers = $this->graph->listSyncEligibleUsers($tenantId);
        } catch (Throwable $e) {
            return ['reverted' => 0, 'errors' => [$e->getMessage()]];
        }

        foreach ($graphUsers as $graphUser) {
            $current = trim((string) ($graphUser['displayName'] ?? ''));

            if (! EntraSyncDisplayName::hasSuperOpsSuffix($current)) {
                continue;
            }

            $restored = EntraSyncDisplayName::baseName($current);

            if ($dryRun) {
                $reverted++;

                continue;
            }

            try {
                $this->graph->updateUserDisplayName($tenantId, $graphUser['id'], $restored);
                $reverted++;
            } catch (Throwable $e) {
                $errors[] = "Failed to revert displayName for {$graphUser['id']}: {$e->getMessage()}";
            }
        }

        return ['reverted' => $reverted, 'errors' => $errors];
    }

    private function shouldSetSuperOpsNameHint(): bool
    {
        return (int) config('services.entra_sync.superops_name_extension_attribute', 1) > 0;
    }

    private function shouldAssignToSuperOpsApp(EntraIdentityType $identityType, bool $accountEnabled): bool
    {
        return $identityType === EntraIdentityType::SharedMailbox
            || ($identityType === EntraIdentityType::User && $accountEnabled);
    }

    private function shouldMaintainSuperOpsAppUsers(Client $client): bool
    {
        return filled($client->entra_superops_app_id);
    }

    private function shouldMaintainRequesterSsoUsers(Client $client): bool
    {
        return ($client->entra_license_tier ?? 'free') === 'free'
            && (bool) (($client->onboarding_checklist ?? [])['superops_client_sso_configured'] ?? false)
            && filled(config('services.superops.requester_sso_client_id'));
    }

    private function shouldTriggerSuperOpsScimProvision(Client $client): bool
    {
        return config('services.entra_sync.superops_provision_on_demand')
            && filled($client->entra_superops_app_id);
    }

    /**
     * Push updated SuperOps requester names via Entra SCIM provision-on-demand (Graph).
     *
     * @param  list<string>  $userIds
     * @return array{0: int, 1: list<string>}
     */
    private function triggerSuperOpsScimProvision(Client $client, array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [0, []];
        }

        $tenantId = (string) $client->entra_tenant_id;
        $configuredId = (string) $client->entra_superops_app_id;

        try {
            $servicePrincipalId = $this->graph->resolveEnterpriseServicePrincipalId($tenantId, $configuredId);
            $context = $this->graph->resolveSuperOpsScimProvisioningContext($tenantId, $servicePrincipalId);
            $provisioned = $this->graph->provisionUsersOnDemand(
                $tenantId,
                $servicePrincipalId,
                $context['jobId'],
                $context['userRuleId'],
                $userIds,
            );

            Log::info('SuperOps SCIM provision on demand completed', [
                'client_id' => $client->id,
                'provisioned' => $provisioned,
            ]);

            return [$provisioned, []];
        } catch (Throwable $e) {
            Log::error('SuperOps SCIM provision on demand failed', [
                'client_id' => $client->id,
                'configured_id' => $configuredId,
                'error' => $e->getMessage(),
            ]);

            return [0, ['SuperOps SCIM provision on demand failed: '.$e->getMessage()]];
        }
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
     * Assign licensed active users directly to the SuperOps enterprise app (Entra ID Free workaround).
     * When the customer cannot assign security groups to enterprise apps, SCIM only provisions assigned users.
     *
     * @param  list<string>  $desiredUserIds
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function syncSuperOpsAppUserAssignments(Client $client, array $desiredUserIds, bool $dryRun): array
    {
        $tenantId = (string) $client->entra_tenant_id;
        $configuredId = (string) $client->entra_superops_app_id;

        try {
            $servicePrincipalId = $this->graph->resolveEnterpriseServicePrincipalId($tenantId, $configuredId);

            if ($servicePrincipalId !== $configuredId) {
                Log::info('Resolved SuperOps enterprise app service principal ID', [
                    'client_id' => $client->id,
                    'configured_id' => $configuredId,
                    'service_principal_id' => $servicePrincipalId,
                ]);
            }

            $currentAssignments = $this->graph->listAppAssignedUsers($tenantId, $servicePrincipalId);
            $appRoleId = $this->graph->resolveAssignableAppRoleId($tenantId, $servicePrincipalId);
        } catch (Throwable $e) {
            Log::error('Entra SuperOps app assignment read failed', [
                'client_id' => $client->id,
                'configured_id' => $configuredId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [0, 0, ['SuperOps app user sync failed: '.$e->getMessage()]];
        }

        $desired = array_values(array_unique($desiredUserIds));
        $currentUserIds = array_keys($currentAssignments);

        $toAssign = array_values(array_diff($desired, $currentUserIds));
        $toRemove = array_values(array_diff($currentUserIds, $desired));

        if ($dryRun) {
            return [count($toAssign), count($toRemove), []];
        }

        $assigned = 0;
        $removed = 0;
        $errors = [];

        foreach ($toAssign as $userId) {
            try {
                $this->graph->assignUserToEnterpriseApp($tenantId, $servicePrincipalId, $userId, $appRoleId);
                $assigned++;
            } catch (Throwable $e) {
                $errors[] = "Failed to assign {$userId} to SuperOps app: {$e->getMessage()}";
            }
        }

        foreach ($toRemove as $userId) {
            $assignmentId = $currentAssignments[$userId] ?? null;

            if ($assignmentId === null) {
                continue;
            }

            try {
                $this->graph->removeUserFromEnterpriseApp($tenantId, $servicePrincipalId, $assignmentId);
                $removed++;
            } catch (Throwable $e) {
                $errors[] = "Failed to remove {$userId} from SuperOps app: {$e->getMessage()}";
            }
        }

        return [$assigned, $removed, $errors];
    }

    /**
     * Keep Entra ID Free requester SSO access aligned with active licensed users.
     *
     * Group assignment requires Entra ID P1. On Free, the portal assigns users
     * directly to the customer tenant's service principal created by step 08 Accept.
     *
     * @param  list<string>  $desiredUserIds
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function syncRequesterSsoUserAssignments(Client $client, array $desiredUserIds, bool $dryRun): array
    {
        $tenantId = (string) $client->entra_tenant_id;
        $applicationClientId = (string) config('services.superops.requester_sso_client_id');

        try {
            $servicePrincipalId = $this->graph->resolveEnterpriseServicePrincipalId(
                $tenantId,
                $applicationClientId,
            );
            $currentAssignments = $this->graph->listAppAssignedUsers($tenantId, $servicePrincipalId);
        } catch (Throwable $e) {
            Log::error('Entra SuperOps requester SSO assignment read failed', [
                'client_id' => $client->id,
                'application_client_id' => $applicationClientId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [0, 0, [
                'SuperOps SSO user sync failed. Complete checklist step 08 customer Accept, then Sync now again: '
                .$e->getMessage(),
            ]];
        }

        $desired = array_values(array_unique($desiredUserIds));
        $currentUserIds = array_keys($currentAssignments);
        $toAssign = array_values(array_diff($desired, $currentUserIds));
        $toRemove = array_values(array_diff($currentUserIds, $desired));

        if ($dryRun) {
            return [count($toAssign), count($toRemove), []];
        }

        $assigned = 0;
        $removed = 0;
        $errors = [];

        foreach ($toAssign as $userId) {
            try {
                $this->graph->assignUserToEnterpriseAppDefaultAccess(
                    $tenantId,
                    $servicePrincipalId,
                    $userId,
                );
                $assigned++;
            } catch (Throwable $e) {
                $errors[] = "Failed to grant SuperOps SSO access to {$userId}: {$e->getMessage()}";
            }
        }

        foreach ($toRemove as $userId) {
            $assignmentId = $currentAssignments[$userId] ?? null;

            if ($assignmentId === null) {
                continue;
            }

            try {
                $this->graph->removeUserFromEnterpriseApp($tenantId, $servicePrincipalId, $assignmentId);
                $removed++;
            } catch (Throwable $e) {
                $errors[] = "Failed to remove SuperOps SSO access from {$userId}: {$e->getMessage()}";
            }
        }

        return [$assigned, $removed, $errors];
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
