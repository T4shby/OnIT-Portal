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

        try {
            $tier = $this->graph->detectEntraDirectoryLicenseTier($tenantId);
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
            $groupId = $this->graph->ensurePortalSecurityGroup($tenantId, $groupName);
            $fields['entra_group_id'] = $groupId;
            $details[] = "Portal group «{$groupName}»: {$groupId}";
        } catch (Throwable $e) {
            Log::error('Entra bootstrap group failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Group not created: '.$e->getMessage();
            $client->update($fields);
            $this->onboarding->updateChecklist($client->fresh(), [
                'entra_admin_consent_granted' => true,
            ]);

            return [
                'ok' => false,
                'summary' => 'Accept saved but portal group could not be created.',
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
            $scim = $this->graph->ensureNamedEnterpriseApplication($tenantId, $scimAppName);
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
                    $roleId = $this->graph->resolveAssignableAppRoleId($tenantId, $scim['servicePrincipalId']);
                    $this->graph->assignGroupToEnterpriseApp(
                        $tenantId,
                        $scim['servicePrincipalId'],
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
            $sso = $this->graph->ensureNamedEnterpriseApplication($tenantId, $ssoAppName);
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
                    $roleId = $this->graph->resolveAssignableAppRoleId($tenantId, $sso['servicePrincipalId']);
                    $this->graph->assignGroupToEnterpriseApp(
                        $tenantId,
                        $sso['servicePrincipalId'],
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

        $warnings[] = 'Still required in SuperOps: SCIM Generate Tokens → paste into SCIM app Provisioning, then Start provisioning (step 05–07).';
        $warnings[] = 'Still required for Client SSO: SuperOps Client SSO configuration + SAML Identifier / Reply URL / Login URL + cert (step 08).';

        $client->update($fields);
        $client = $client->fresh();
        $this->onboarding->updateChecklist($client, $checklist);
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $ok = ! array_key_exists('entra_group_id', $fields) || filled($client->entra_group_id);

        return [
            'ok' => $ok && filled($client->entra_tenant_id) && filled($client->entra_group_id),
            'summary' => $ok
                ? 'Microsoft tenant connected — tenant, licence, group and Entra apps saved where possible.'
                : 'Microsoft Accept completed with errors — check warnings.',
            'details' => $details,
            'warnings' => $warnings,
        ];
    }
}
