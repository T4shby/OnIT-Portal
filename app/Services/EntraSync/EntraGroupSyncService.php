<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Jobs\ProvisionSuperOpsScimUsersJob;
use App\Models\Client;
use App\Models\User;
use App\Services\ExternalServicesService;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EntraGroupSyncService
{
    public function __construct(
        private MicrosoftGraphClient $graph,
        private ExternalServicesService $portalLinks,
        private SuperOpsUserSyncService $superOpsUsers,
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
        $superOpsApiNamesUpdated = 0;
        /** @var array<string, array{firstName: string, lastName: string}> */
        $superOpsApiNameQueue = [];
        $errors = [];
        $activeObjectIds = [];
        $activeEmails = [];
        $desiredGroupMemberIds = [];
        $desiredSuperOpsAppUserIds = [];
        $desiredRequesterSsoUserIds = [];
        $scimProvisionUserIds = [];

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
                $superOpsGivenName = EntraSyncDisplayName::formatSuperOpsGivenName(
                    $graphUser['givenName'] ?? null,
                    $graphUser['displayName'],
                    $email,
                );

                $currentHint = $graphUser['superOpsNameHint'] ?? null;

                if ($currentHint !== $superOpsFamilyName) {
                    if ($dryRun) {
                        $superOpsNameHintsUpdated++;
                        $scimProvisionUserIds[] = $graphUser['id'];
                    } else {
                        try {
                            $this->graph->setSuperOpsNameExtensionAttribute(
                                (string) $client->entra_tenant_id,
                                $graphUser['id'],
                                $superOpsFamilyName,
                            );
                            $superOpsNameHintsUpdated++;
                            $scimProvisionUserIds[] = $graphUser['id'];
                        } catch (Throwable $e) {
                            // Hybrid / AD-synced users cannot receive Graph writes to extensionAttribute1.
                            // Queue SuperOps API name push so requesters still get (User Mailbox)/(Shared Mailbox).
                            if ($this->shouldQueueSuperOpsApiNameFallback($e->getMessage())) {
                                $superOpsApiNameQueue[strtolower($email)] = [
                                    'firstName' => $superOpsGivenName,
                                    'lastName' => $superOpsFamilyName,
                                ];
                            } else {
                                $errors[] = "Failed to set SuperOps last name for {$email}: {$e->getMessage()}";
                            }
                        }
                    }
                }
            }

            $objectId = (string) $graphUser['id'];
            $existing = $this->resolvePortalUserForGraphIdentity($client, $objectId, $email);

            if ($existing && $existing->client_id !== null && (int) $existing->client_id !== (int) $client->id) {
                $skipped++;
                $errors[] = "Entra object {$objectId} / email {$email} already belongs to another client (user #{$existing->id}).";

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
                'entra_object_id' => $objectId,
                'entra_identity_type' => $identityType,
                'is_active' => $shouldBeActive,
                'portal_login_enabled' => $portalLoginEnabled,
                'provisioned_by' => UserProvisionSource::EntraSync,
                'entra_synced_at' => now(),
            ];

            if ($existing) {
                $emailClaimed = $this->claimPrimaryEmailForUser($existing, $client, $email, $objectId, $errors);
                if (! $emailClaimed) {
                    $skipped++;

                    continue;
                }

                $attributes['email'] = $email;
                $existing->update($attributes);
                $updated++;
            } else {
                $emailOwner = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->first();

                if ($emailOwner !== null) {
                    $skipped++;
                    $errors[] = "Email {$email} already belongs to user #{$emailOwner->id} and could not be claimed for a new portal row.";

                    continue;
                }

                User::create(array_merge($attributes, [
                    'client_id' => $client->id,
                    'email' => $email,
                    'role' => UserRole::ClientRequester,
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

        $newlyAssignedScimUserIds = [];

        if ($this->shouldMaintainSuperOpsAppUsers($client)) {
            [$superOpsAppUsersAssigned, $superOpsAppUsersRemoved, $appErrors, $newlyAssignedScimUserIds] = $this->syncSuperOpsAppUserAssignments(
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

        if (! $dryRun && $superOpsApiNameQueue !== [] && (bool) config('services.entra_sync.superops_name_api_fallback', true)) {
            try {
                $superOpsApiNamesUpdated = $this->superOpsUsers->pushRequesterNames($client, $superOpsApiNameQueue);
                if ($superOpsApiNamesUpdated === 0 && $superOpsApiNameQueue !== []) {
                    $errors[] = 'Graph could not write SuperOps names for hybrid users and SuperOps API update matched 0 requesters '
                        .'(check superops_account_id and that those emails exist as SuperOps requesters).';
                }
            } catch (Throwable $e) {
                $errors[] = 'SuperOps API name fallback failed: '.$e->getMessage();
            }
        }

        if (! $dryRun && $this->shouldTriggerSuperOpsScimProvision($client)) {
            $scimProvisionUserIds = array_values(array_unique(array_merge(
                $scimProvisionUserIds,
                $newlyAssignedScimUserIds,
            )));

            if ($scimProvisionUserIds !== []) {
                if ($superOpsNameHintsUpdated > 0) {
                    $delaySeconds = max(0, (int) config('services.entra_sync.superops_provision_delay_after_names_seconds', 3));

                    if ($delaySeconds > 0 && config('queue.default') === 'sync') {
                        sleep($delaySeconds);
                    }
                }

                [$superOpsUsersProvisioned, $provisionErrors] = $this->queueOrRunSuperOpsScimProvision(
                    $client,
                    $scimProvisionUserIds,
                );
                $errors = array_merge($errors, $provisionErrors);
            }
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
            superOpsApiNamesUpdated: $superOpsApiNamesUpdated,
            superOpsUsersProvisioned: $superOpsUsersProvisioned,
            errors: $errors,
        );
    }

    private function shouldQueueSuperOpsApiNameFallback(string $message): bool
    {
        if (! (bool) config('services.entra_sync.superops_name_api_fallback', true)) {
            return false;
        }

        $needle = strtolower($message);

        return str_contains($needle, 'on-premises')
            || str_contains($needle, 'onpremises')
            || str_contains($needle, 'directory sync')
            || str_contains($needle, 'originated within an external service')
            || str_contains($needle, 'cannot update the specified properties');
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
            && filled($client->entra_superops_sso_app_id);
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
    public function provisionSuperOpsScimUsers(Client $client, array $userIds): array
    {
        return $this->triggerSuperOpsScimProvision($client, $userIds);
    }

    /**
     * @param  list<string>  $userIds
     * @return array{0: int, 1: list<string>}
     */
    private function queueOrRunSuperOpsScimProvision(Client $client, array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [0, []];
        }

        // PHPUnit uses QUEUE_CONNECTION=sync — keep provision inline so tests assert counts.
        if (config('queue.default') === 'sync') {
            return $this->triggerSuperOpsScimProvision($client, $userIds);
        }

        $pendingKey = 'entra_scim_provision_pending.'.$client->id;
        $pending = Cache::get($pendingKey, []);
        $merged = array_values(array_unique(array_merge(
            is_array($pending) ? $pending : [],
            $userIds,
        )));
        Cache::put($pendingKey, $merged, now()->addMinutes(30));

        ProvisionSuperOpsScimUsersJob::dispatch($client->id);

        Log::info('Queued SuperOps SCIM provision on demand', [
            'client_id' => $client->id,
            'user_count' => count($merged),
        ]);

        return [count($merged), []];
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
     * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
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

            return [0, 0, ['SuperOps app user sync failed: '.$e->getMessage()], []];
        }

        $desired = array_values(array_unique($desiredUserIds));
        $currentUserIds = array_keys($currentAssignments);

        $toAssign = array_values(array_diff($desired, $currentUserIds));
        $toRemove = array_values(array_diff($currentUserIds, $desired));

        if ($dryRun) {
            return [count($toAssign), count($toRemove), [], $toAssign];
        }

        $assigned = 0;
        $removed = 0;
        $errors = [];
        $newlyAssigned = [];

        foreach ($toAssign as $userId) {
            try {
                $this->graph->assignUserToEnterpriseApp($tenantId, $servicePrincipalId, $userId, $appRoleId);
                $assigned++;
                $newlyAssigned[] = $userId;
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

        return [$assigned, $removed, $errors, $newlyAssigned];
    }

    /**
     * Keep Entra ID Free requester SSO access aligned with active licensed users.
     *
     * Group assignment requires Entra ID P1. On Free, the portal assigns users
     * directly to the customer-owned Client SSO service principal created in step 08.
     *
     * @param  list<string>  $desiredUserIds
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function syncRequesterSsoUserAssignments(Client $client, array $desiredUserIds, bool $dryRun): array
    {
        $tenantId = (string) $client->entra_tenant_id;
        $applicationClientId = (string) $client->entra_superops_sso_app_id;

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
                'SuperOps SSO user sync failed. Finish step 08, save the Client SSO Application ID, then Sync now again: '
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
     * Prefer Entra object id (stable across primary-email / domain changes). Fall back to email for first bind.
     */
    private function resolvePortalUserForGraphIdentity(Client $client, string $objectId, string $email): ?User
    {
        $byObjectId = User::query()
            ->where('entra_object_id', $objectId)
            ->orderByRaw('CASE WHEN client_id = ? THEN 0 ELSE 1 END', [$client->id])
            ->orderBy('id')
            ->first();

        if ($byObjectId !== null) {
            return $byObjectId;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    /**
     * Move keeper onto the Graph primary email. Retires same-client Entra-sync shadows that hold the address
     * or share the same object id (domain renames that previously doubled rows).
     *
     * @param  list<string>  $errors
     */
    private function claimPrimaryEmailForUser(
        User $keeper,
        Client $client,
        string $newEmail,
        string $objectId,
        array &$errors,
    ): bool {
        $newEmail = strtolower($newEmail);

        // Same-object-id duplicates from pre-fix domain renames.
        $shadows = User::query()
            ->where('entra_object_id', $objectId)
            ->where('id', '!=', $keeper->id)
            ->get();

        foreach ($shadows as $shadow) {
            if ((int) $shadow->client_id !== (int) $client->id) {
                $errors[] = "Entra object {$objectId} is also on user #{$shadow->id} (other client) — resolve manually.";

                return false;
            }
            if ($shadow->provisioned_by === UserProvisionSource::Manual) {
                $errors[] = "Manual user #{$shadow->id} shares Entra object {$objectId} with #{$keeper->id} — resolve manually.";

                return false;
            }
            $this->retireShadowEntraUser($shadow, $keeper);
        }

        if (strtolower((string) $keeper->email) === $newEmail) {
            return true;
        }

        $emailOwner = User::query()
            ->whereRaw('LOWER(email) = ?', [$newEmail])
            ->where('id', '!=', $keeper->id)
            ->first();

        if ($emailOwner === null) {
            return true;
        }

        if ((int) $emailOwner->client_id !== (int) $client->id) {
            $errors[] = "Email {$newEmail} already belongs to another client (user #{$emailOwner->id}).";

            return false;
        }

        if ($emailOwner->provisioned_by === UserProvisionSource::Manual) {
            $errors[] = "Email {$newEmail} belongs to manual user #{$emailOwner->id} — resolve manually.";

            return false;
        }

        if (in_array($emailOwner->role, [UserRole::SuperAdmin, UserRole::AccountManager], true)) {
            $errors[] = "Email {$newEmail} belongs to staff user #{$emailOwner->id}.";

            return false;
        }

        $this->retireShadowEntraUser($emailOwner, $keeper);

        return true;
    }

    /**
     * Free unique email + object id for the keeper; prefer elevated client role from either row.
     */
    private function retireShadowEntraUser(User $shadow, User $keeper): void
    {
        if ($this->clientRolePriority($shadow->role) > $this->clientRolePriority($keeper->role)) {
            $keeper->role = $shadow->role;
            $keeper->save();
        }

        if (blank($keeper->superops_user_id) && filled($shadow->superops_user_id)) {
            $keeper->superops_user_id = $shadow->superops_user_id;
            $keeper->save();
        }

        $retiredEmail = sprintf('retired+%d.%s@portal.invalid', $shadow->id, str_replace('.', '', uniqid('', true)));

        $shadow->update([
            'email' => $retiredEmail,
            'entra_object_id' => null,
            'is_active' => false,
            'portal_login_enabled' => false,
            'entra_synced_at' => now(),
        ]);
    }

    private function clientRolePriority(UserRole $role): int
    {
        return match ($role) {
            UserRole::ClientAdmin => 40,
            UserRole::ClientBillingAdmin => 30,
            UserRole::ClientUser => 20,
            UserRole::ClientRequester => 10,
            default => 0,
        };
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
     * Deactivate Entra-synced users no longer in tenant scope.
     * Object id is authoritative — email change alone must not deactivate.
     *
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
                if (filled($user->entra_object_id)) {
                    return ! in_array($user->entra_object_id, $activeObjectIds, true);
                }

                return ! in_array(strtolower($user->email), $activeEmails, true);
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

    public function syncAll(?int $clientId = null, bool $dryRun = false, ?callable $beforeClient = null): array
    {
        $query = Client::query()
            ->where('is_active', true)
            ->where('entra_sync_enabled', true)
            ->whereNotNull('entra_tenant_id');

        if ($clientId) {
            $query->whereKey($clientId);
        }

        $results = [];

        foreach ($query->orderBy('id')->get() as $client) {
            if ($beforeClient !== null) {
                $beforeClient($client);
            }

            $results[$client->id] = [
                'client' => $client->name,
                'result' => $this->syncClient($client, $dryRun),
            ];
        }

        return $results;
    }
}
