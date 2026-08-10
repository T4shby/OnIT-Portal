<?php

namespace App\Services\EntraSync;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After Portal Graph Accept in a customer tenant: save tenant / licence / group and
 * create or link SuperOps SCIM + Client SSO enterprise apps (Entra-side).
 *
 * SuperOps UI steps still required only when not already complete: SCIM tokens
 * (Tenant URL + Secret) and Client SSO wire — remaining prompts are driven from
 * checklist state, not a hard-coded every-time warning.
 *
 * Always waits/retries Graph after Accept — Azure often returns IdentityNotFound /
 * "insufficient privileges" for a short time even when consent succeeded.
 *
 * Progress is saved after each stage so P1/tenant are never lost if group create fails.
 * Group failure no longer blocks SuperOps Entra app creation.
 */
class CustomerEntraBootstrapService
{
    public function __construct(
        private MicrosoftGraphClient $graph,
        private ClientOnboardingService $onboarding,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     details: list<string>,
     *     warnings: list<string>,
     * }
     */
    public function bootstrap(Client $client, ?string $tenantIdFromConsent = null): array
    {
        $details = [];
        $warnings = [];

        $tenantId = filled($tenantIdFromConsent)
            ? strtolower(trim($tenantIdFromConsent))
            : (string) $client->entra_tenant_id;

        if ($tenantId === '') {
            return [
                'ok' => false,
                'summary' => 'No tenant ID from Accept or client record.',
                'details' => [],
                'warnings' => ['Run Connect Microsoft tenant first (Accept).'],
            ];
        }

        if (! $this->graph->isConfigured()) {
            return [
                'ok' => false,
                'summary' => 'Portal Graph client is not configured.',
                'details' => [],
                'warnings' => ['Set MICROSOFT_CLIENT_ID / MICROSOFT_CLIENT_SECRET (or ENTRA_SYNC_*).'],
            ];
        }

        $this->graph->clearAccessTokenCache($tenantId);

        $fields = [
            'entra_tenant_id' => $tenantId,
        ];
        $details[] = 'Tenant ID: '.$tenantId;
        $this->persist($client, $fields);

        $ready = $this->graph->waitUntilAppOnlyGraphReady($tenantId);
        if ($ready['ready']) {
            $details[] = 'Graph ready (waited '.$ready['attempts'].' probe'
                .($ready['attempts'] === 1 ? '' : 's').' after Accept).';
        } else {
            $warnings[] = 'Graph still settling after Accept'
                .($ready['last_error'] ? ' ('.$ready['last_error'].')' : '')
                .'. Continuing with retries — if group/apps stay empty use **Retry Graph setup**.';
            Log::warning('Entra bootstrap Graph not ready after wait', [
                'client_id' => $client->id,
                'attempts' => $ready['attempts'],
                'error' => $ready['last_error'],
            ]);
        }

        try {
            $tier = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->detectEntraDirectoryLicenseTier($tenantId),
            );
            $fields['entra_license_tier'] = $tier;
            $details[] = 'Entra licence tier: '.($tier === ClientOnboardingService::ENTRA_LICENSE_P1 ? 'P1 or higher' : 'Free')
                .' (saved on client)';
            $this->persist($client, $fields);
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap licence detection failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $tier = ClientOnboardingService::ENTRA_LICENSE_FREE;
            $fields['entra_license_tier'] = $tier;
            $warnings[] = 'Could not read licence SKUs — left as Free. Set tier on the left if the tenant is P1. ('.$e->getMessage().')';
            $this->persist($client, $fields);
        }

        $groupName = 'On IT Portal - '.$client->name;
        $groupId = null;

        try {
            $groupId = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensurePortalSecurityGroup($tenantId, $groupName),
            );
            $fields['entra_group_id'] = $groupId;
            $details[] = "Portal group «{$groupName}»: {$groupId}";
            $this->persist($client, $fields);
        } catch (Throwable $e) {
            Log::error('Entra bootstrap group failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Group not created: '.$e->getMessage()
                .' Licence tier is still saved. Continuing SuperOps Entra apps without a group. '
                .'Fix: On IT Azure app **Group.ReadWrite.All** → Grant admin consent on On IT app → '
                .'Re-consent in customer tenant (details under Retry) → **Retry Graph setup**. '
                .'Or create group «'.$groupName.'» in customer Entra and paste its Object ID on the left.';
            $groupId = null;
        }

        $scimAppName = 'SuperOps - '.$client->name;
        $ssoAppName = 'SuperOps Requester SSO - '.$client->name;
        $usesGroupScim = $tier === ClientOnboardingService::ENTRA_LICENSE_P1;
        $checklist = [
            'entra_admin_consent_granted' => true,
        ];
        if (filled($groupId)) {
            $checklist['entra_group_created'] = true;
        }

        try {
            $scim = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensureNamedEnterpriseApplication(
                    $tenantId,
                    $scimAppName,
                    $client->entra_superops_app_id ?? ($fields['entra_superops_app_id'] ?? null),
                ),
            );
            $fields['entra_superops_app_id'] = $scim['appId'];
            $details[] = "SCIM app «{$scimAppName}»: {$scim['appId']}";
            $checklist['superops_scim_app'] = true;
            $this->persist($client, $fields);

            try {
                $this->graph->ensureApplicationUserRole(
                    $tenantId,
                    $scim['applicationObjectId'],
                    $scim['appId'],
                );
            } catch (Throwable $e) {
                $warnings[] = 'SCIM app role User incomplete (app ID is saved): '.$e->getMessage();
            }

            if ($usesGroupScim && filled($groupId)) {
                try {
                    $scimSpId = $this->graph->waitForServicePrincipalForAppId(
                        $tenantId,
                        $scim['appId'],
                        $scim['servicePrincipalId'],
                    );
                    $roleId = $this->graph->resolveAssignableAppRoleId(
                        $tenantId,
                        $scimSpId,
                        $scim['appId'],
                    );
                    $this->graph->assignGroupToEnterpriseApp(
                        $tenantId,
                        $scimSpId,
                        $groupId,
                        $roleId,
                        $scim['appId'],
                    );
                    $details[] = "Assigned «{$groupName}» to SCIM app (P1).";
                } catch (Throwable $e) {
                    $warnings[] = 'SCIM group assign skipped: '.$e->getMessage();
                }
            } elseif ($usesGroupScim && ! filled($groupId)) {
                $warnings[] = 'SCIM group assign skipped until portal group exists.';
            }
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap SCIM app failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $this->appendEnterpriseAppFailureWarning(
                $warnings,
                $details,
                'SCIM',
                $client->entra_superops_app_id ?? ($fields['entra_superops_app_id'] ?? null),
                $e,
            );
        }

        try {
            $sso = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensureNamedEnterpriseApplication(
                    $tenantId,
                    $ssoAppName,
                    $client->entra_superops_sso_app_id ?? ($fields['entra_superops_sso_app_id'] ?? null),
                ),
            );
            $fields['entra_superops_sso_app_id'] = $sso['appId'];
            $details[] = "Client SSO app «{$ssoAppName}»: {$sso['appId']}";
            $this->persist($client, $fields);

            try {
                $this->graph->ensureApplicationUserRole(
                    $tenantId,
                    $sso['applicationObjectId'],
                    $sso['appId'],
                );
            } catch (Throwable $e) {
                $warnings[] = 'Client SSO app role User incomplete (app ID is saved): '.$e->getMessage();
            }

            // Give Graph time to publish the new SP + appRoles before group assignment.
            usleep(2_000_000);

            if ($usesGroupScim && filled($groupId)) {
                try {
                    $ssoSpId = $this->graph->waitForServicePrincipalForAppId(
                        $tenantId,
                        $sso['appId'],
                        $sso['servicePrincipalId'],
                        maxAttempts: 16,
                    );
                    $roleId = $this->graph->resolveAssignableAppRoleId(
                        $tenantId,
                        $ssoSpId,
                        $sso['appId'],
                    );
                    $this->graph->assignGroupToEnterpriseApp(
                        $tenantId,
                        $ssoSpId,
                        $groupId,
                        $roleId,
                        $sso['appId'],
                    );
                    $details[] = "Assigned «{$groupName}» to Client SSO app (P1).";
                } catch (Throwable $e) {
                    $warnings[] = 'Client SSO group assign deferred (app IDs are saved): '.$e->getMessage()
                        .' — use **Retry Graph setup** on Edit client, or assign group manually in Entra Users and groups. Not required to continue SCIM / Client SSO wire.';
                }
            } elseif ($usesGroupScim && ! filled($groupId)) {
                $warnings[] = 'Client SSO group assign skipped until portal group exists.';
            }
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap SSO app failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $this->appendEnterpriseAppFailureWarning(
                $warnings,
                $details,
                'Client SSO',
                $client->entra_superops_sso_app_id ?? ($fields['entra_superops_sso_app_id'] ?? null),
                $e,
            );
        }

        $client = $this->persist($client, $fields);
        $this->onboarding->updateChecklist($client, $checklist);
        $this->onboarding->syncAutoCheckpointsFromClient($client);
        $client = $client->fresh() ?? $client;

        foreach ($this->remainingWorkWarnings($client) as $remaining) {
            $warnings[] = $remaining;
        }

        $hasGroup = filled($client->entra_group_id);
        $hasApps = filled($client->entra_superops_app_id) || filled($client->entra_superops_sso_app_id);
        $ok = filled($client->entra_tenant_id) && $hasGroup;

        if ($ok) {
            $summary = 'Microsoft tenant connected — tenant, licence, group and Entra apps saved where possible.';
        } elseif ($hasApps && ! $hasGroup) {
            $summary = 'Tenant and SuperOps apps saved; portal group still missing — fix Group.ReadWrite.All or paste Group ID, then Retry Graph setup.';
        } else {
            $summary = 'Microsoft Accept completed with errors — check warnings and use Retry Graph setup.';
        }

        return [
            'ok' => $ok,
            'summary' => $summary,
            'details' => $details,
            'warnings' => $warnings,
        ];
    }

    /**
     * Only prompt for SCIM paste / Client SSO when the checklist still needs them.
     *
     * @return list<string>
     */
    private function remainingWorkWarnings(Client $client): array
    {
        $checklist = is_array($client->onboarding_checklist) ? $client->onboarding_checklist : [];
        $out = [];

        if (! $this->onboarding->isScimProvisioningComplete($client, $checklist)) {
            $out[] = 'Still required: paste SuperOps SCIM Tenant URL + Secret → **Apply SCIM credentials + start** (or finish Azure Provisioning + name mappings).';
        }

        if (! (bool) ($checklist['superops_client_sso_configured'] ?? false)) {
            $out[] = 'Still required: Client SSO SAML (step 08) — wire SuperOps ↔ Entra if not done.';
        }

        return $out;
    }

    /**
     * Prefer keeping saved app IDs; only scare about Application.ReadWrite when nothing is linked yet.
     *
     * @param  list<string>  $warnings
     * @param  list<string>  $details
     */
    private function appendEnterpriseAppFailureWarning(
        array &$warnings,
        array &$details,
        string $label,
        mixed $savedAppId,
        Throwable $e,
    ): void {
        $msg = $e->getMessage();
        $saved = filled($savedAppId) ? (string) $savedAppId : '';

        if ($saved !== '') {
            $details[] = "{$label} app ID already on client record: {$saved} (Graph could not re-ensure: {$msg}). Keep this ID — sign in → Edit client → **Retry Graph setup** after consent settles, without wiping SuperOps links.";

            return;
        }

        $warnings[] = "{$label} app not ready: {$msg}";
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function persist(Client $client, array $fields): Client
    {
        $client->forceFill($fields)->save();

        return $client->fresh() ?? $client;
    }
}
