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
 * SuperOps UI steps still required: SCIM tokens (Tenant URL + Secret) and Client SSO
 * Entity ID / Consumer URL / Login URL exchange.
 *
 * Always waits/retries Graph after Accept — Azure often returns IdentityNotFound /
 * "insufficient privileges" for a short time even when consent succeeded.
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

        // Save tenant immediately so Retry Graph works if this request times out.
        $client->update($fields);

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
            $details[] = 'Entra licence tier: '.($tier === ClientOnboardingService::ENTRA_LICENSE_P1 ? 'P1 or higher' : 'Free');
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap licence detection failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $tier = ClientOnboardingService::ENTRA_LICENSE_FREE;
            $fields['entra_license_tier'] = $tier;
            $warnings[] = 'Could not read licence SKUs — left as Free. Set tier on the left if the tenant is P1. ('.$e->getMessage().')';
        }

        $groupName = 'On IT Portal - '.$client->name;

        try {
            $groupId = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensurePortalSecurityGroup($tenantId, $groupName),
            );
            $fields['entra_group_id'] = $groupId;
            $details[] = "Portal group «{$groupName}»: {$groupId}";
        } catch (Throwable $e) {
            Log::error('Entra bootstrap group failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Group not created: '.$e->getMessage()
                .(str_contains(strtolower($e->getMessage()), 'group.readwrite')
                    ? ''
                    : ' If this is shortly after Accept, wait ~30s and use **Retry Graph setup**.');
            $client->update($fields);
            $this->onboarding->updateChecklist($client->fresh(), [
                'entra_admin_consent_granted' => true,
            ]);

            return [
                'ok' => false,
                'summary' => 'Accept saved (tenant ID kept) but portal group could not be created yet.',
                'details' => $details,
                'warnings' => $warnings,
            ];
        }

        $scimAppName = 'SuperOps - '.$client->name;
        $ssoAppName = 'SuperOps Requester SSO - '.$client->name;
        $usesGroupScim = $tier === ClientOnboardingService::ENTRA_LICENSE_P1;
        $checklist = [
            'entra_group_created' => true,
            'entra_admin_consent_granted' => true,
        ];

        try {
            $scim = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensureNamedEnterpriseApplication($tenantId, $scimAppName),
            );
            $fields['entra_superops_app_id'] = $scim['appId'];
            $details[] = "SCIM app «{$scimAppName}»: {$scim['appId']}";
            $checklist['superops_scim_app'] = true;

            try {
                $this->graph->ensureApplicationUserRole(
                    $tenantId,
                    $scim['applicationObjectId'],
                    $scim['appId'],
                );
            } catch (Throwable $e) {
                $warnings[] = 'SCIM app role User incomplete (app ID is saved): '.$e->getMessage();
            }

            if ($usesGroupScim) {
                try {
                    // Re-resolve SP after role patch — Graph often 404s stale SP ids from create/instantiate.
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
                    );
                    $details[] = "Assigned «{$groupName}» to SCIM app (P1).";
                } catch (Throwable $e) {
                    $warnings[] = 'SCIM group assign skipped: '.$e->getMessage();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap SCIM app failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'SCIM app not ready: '.$e->getMessage();
        }

        try {
            $sso = $this->graph->retryAfterConsentPropagation(
                $tenantId,
                fn () => $this->graph->ensureNamedEnterpriseApplication($tenantId, $ssoAppName),
            );
            $fields['entra_superops_sso_app_id'] = $sso['appId'];
            $details[] = "Client SSO app «{$ssoAppName}»: {$sso['appId']}";

            try {
                $this->graph->ensureApplicationUserRole(
                    $tenantId,
                    $sso['applicationObjectId'],
                    $sso['appId'],
                );
            } catch (Throwable $e) {
                $warnings[] = 'Client SSO app role User incomplete (app ID is saved): '.$e->getMessage();
            }

            if ($usesGroupScim) {
                try {
                    $ssoSpId = $this->graph->waitForServicePrincipalForAppId(
                        $tenantId,
                        $sso['appId'],
                        $sso['servicePrincipalId'],
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
                    );
                    $details[] = "Assigned «{$groupName}» to Client SSO app (P1).";
                } catch (Throwable $e) {
                    $warnings[] = 'Client SSO group assign skipped: '.$e->getMessage();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Entra bootstrap SSO app failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Client SSO Entra app not ready: '.$e->getMessage();
        }

        $warnings[] = 'Still required: paste SuperOps SCIM Tenant URL + Secret on Edit Client → **Apply SCIM credentials + start** (or Azure Provisioning). Then Client SSO SAML (step 08).';

        $client->update($fields);
        $client = $client->fresh();
        $this->onboarding->updateChecklist($client, $checklist);
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $ok = filled($client->entra_group_id);

        return [
            'ok' => $ok && filled($client->entra_tenant_id),
            'summary' => $ok
                ? 'Microsoft tenant connected — tenant, licence, group and Entra apps saved where possible.'
                : 'Microsoft Accept completed with errors — check warnings and Retry Graph setup if needed.',
            'details' => $details,
            'warnings' => $warnings,
        ];
    }
}
