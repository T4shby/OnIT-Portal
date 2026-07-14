<?php

namespace App\Services;

use App\Models\Client;

class ClientOnboardingService
{
    public const ENTRA_LICENSE_FREE = 'free';

    public const ENTRA_LICENSE_P1 = 'p1';

    /** @var list<string> */
    public const ENTRA_LICENSE_TIERS = [
        self::ENTRA_LICENSE_FREE,
        self::ENTRA_LICENSE_P1,
    ];

    /** Shown on checklist steps — MSP role labels, not generic "you". */
    public const RESPONSIBLE_ON_IT_PORTAL = 'On IT technician (portal / SuperOps)';

    public const RESPONSIBLE_ON_IT_CUSTOMER_ENTRA = 'On IT technician (customer Entra / GDAP)';

    /**
     * Manual checkpoint keys technicians can tick.
     * Legacy `superops_scim_configured` still accepted so older clients keep completion.
     *
     * @var list<string>
     */
    public const MANUAL_CHECKPOINTS = [
        'entra_group_created',
        'entra_admin_consent_granted',
        'superops_scim_tokens',
        'superops_scim_app',
        'superops_scim_provisioning',
        'superops_scim_configured',
        'superops_client_sso_configured',
        'portal_sync_run',
        'login_tested',
        'handed_off',
    ];

    public function adminConsentUrl(Client $client): ?string
    {
        if (! filled($client->entra_tenant_id)) {
            return null;
        }

        $appClientId = config('services.entra_sync.client_id');

        if (! filled($appClientId)) {
            return null;
        }

        $redirectUri = config('services.azure.redirect');
        $state = $client->exists ? \App\Support\AdminConsentState::encode($client->id) : null;

        $query = http_build_query(array_filter([
            'client_id' => $appClientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]));

        return sprintf(
            'https://login.microsoftonline.com/%s/adminconsent?%s',
            $client->entra_tenant_id,
            $query,
        );
    }

    /**
     * Customer Global Admin Accept URL for SuperOps Requester SSO (On IT).
     */
    public function superOpsRequesterSsoConsentUrl(Client $client): ?string
    {
        if (! filled($client->entra_tenant_id)) {
            return null;
        }

        $appClientId = config('services.superops.requester_sso_client_id');

        if (! filled($appClientId)) {
            return null;
        }

        $query = http_build_query([
            'client_id' => $appClientId,
        ]);

        return sprintf(
            'https://login.microsoftonline.com/%s/adminconsent?%s',
            $client->entra_tenant_id,
            $query,
        );
    }

    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     who: string,
     *     instructions: list<string>,
     *     guide: array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>},
     *     complete: bool,
     *     manual: bool,
     *     auto_detected: bool,
     *     blocked: bool,
     * }>
     */
    public function steps(Client $client): array
    {
        $checklist = $client->onboarding_checklist ?? [];
        $groupName = 'On IT Portal - '.$client->name;
        $appName = 'SuperOps - '.$client->name;
        $entraTier = $this->normalizeEntraLicenseTier($client->entra_license_tier);
        $usesGroupScim = $this->usesEntraGroupScim($entraTier);
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        $superopsLinked = filled($client->superops_account_id);
        $pax8Configured = ! $client->pax8_sso_enabled || filled($client->pax8_company_id);
        $entraTenantSaved = filled($client->entra_tenant_id);
        $entraGroupSaved = filled($client->entra_group_id);
        $syncConfigured = $client->hasEntraSyncPrerequisites();
        $syncRun = $client->entra_synced_at !== null;
        $syncEnabledGlobally = (bool) config('services.entra_sync.enabled');

        $entraGroupComplete = ($entraTenantSaved && $entraGroupSaved)
            || (bool) ($checklist['entra_group_created'] ?? false);

        $adminConsentComplete = (bool) ($checklist['entra_admin_consent_granted'] ?? false) || $syncRun;

        $legacyScimComplete = (bool) ($checklist['superops_scim_configured'] ?? false);
        $scimTokensComplete = $legacyScimComplete || (bool) ($checklist['superops_scim_tokens'] ?? false);
        $scimAppComplete = $legacyScimComplete || (bool) ($checklist['superops_scim_app'] ?? false);
        $scimProvisioningComplete = $legacyScimComplete || (bool) ($checklist['superops_scim_provisioning'] ?? false);

        $ssoComplete = (bool) ($checklist['superops_client_sso_configured'] ?? false);
        $portalSyncRunComplete = $syncRun || (bool) ($checklist['portal_sync_run'] ?? false);
        $loginTested = (bool) ($checklist['login_tested'] ?? false);

        return [
            $this->withManual([
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $superopsLinked,
                'manual' => false,
                'auto_detected' => $superopsLinked,
                'blocked' => false,
            ], OnboardingManual::simple(
                $superOpsUrl.' → Clients',
                [
                    'Open SuperOps and go to **Clients**.',
                    'Open this customer.',
                    'Copy the Account ID from the browser URL (the long number after `/client/`).',
                    'On the left of this page, paste it into **SuperOps Account ID**.',
                    'Click **Save client** (left).',
                ],
                verify: [
                    'This step turns Done automatically when the Account ID is saved.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'pax8_linked',
                'title' => 'Link Pax8 (or skip)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $pax8Configured,
                'manual' => false,
                'auto_detected' => $pax8Configured,
                'blocked' => false,
            ], OnboardingManual::simple(
                'Pax8 partner portal, then this page (left)',
                [
                    'If this customer does **not** use the Pax8 tile, leave Pax8 blank and unticked. This step is already Done.',
                    'If they do use Pax8: open Pax8 → Companies → this customer → copy the company UUID.',
                    'On the left: paste **Pax8 Company ID** and tick **Pax8 access**.',
                    'Click **Save client** (left).',
                ],
                verify: [
                    'Pax8 off = Done. Or Pax8 Company ID is saved.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'entra_group_created',
                'title' => 'Create Portal group + save Entra IDs',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraTenantSaved && $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ], OnboardingManual::simple(
                'https://portal.azure.com — customer tenant (not On IT)',
                [
                    'On the left of this page, set **Customer Entra license tier** to Free or P1 to match the customer.',
                    'In Azure, switch directory (top-right) to **'.$client->name.'**.',
                    'Go to **Microsoft Entra ID → Groups → New group**.',
                    'Group type: **Security**. Membership type: **Assigned**.',
                    'Group name: **'.$groupName.'**.',
                    'Leave **Members** empty. Click **Create**.',
                    'Open the new group → Overview → copy **Object ID**.',
                    'Go to **Microsoft Entra ID → Overview** → copy **Tenant ID**.',
                    'On the left of this page: paste **Entra tenant ID** and **Entra group ID**.',
                    'Click **Save client** (left).',
                ],
                verify: [
                    'Both Entra IDs are saved on the left and this step shows Done.',
                ],
                notes: [
                    'Leave the group empty. Sync later fills it for you.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'entra_admin_consent_granted',
                'title' => 'Customer Accepts Portal access',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ], OnboardingManual::simple(
                'Start with the orange button above',
                [
                    'Click **Open Microsoft Accept page** above.',
                    'Sign in as a **'.$client->name.' Global Admin** — not an @onit.ltd account.',
                    'Check the page shows **'.$client->name.'**, not On IT.',
                    'Click **Accept**.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Microsoft showed Accept success for '.$client->name.'.',
                ],
                notes: [
                    'This is not the SuperOps Accept later. This one is for portal sync only.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'superops_scim_tokens',
                'title' => 'Get SuperOps SCIM tokens',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimTokensComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], OnboardingManual::simple(
                $superOpsUrl.' → Integrations',
                [
                    'Open SuperOps.',
                    'Go to **Integrations → Microsoft Entra ID → Generate Tokens**.',
                    'Select **'.$client->name.'**.',
                    'Copy **Tenant URL** and **Secret Token** (also called Auth Token).',
                    'Keep them somewhere safe for the next step.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'You have both the Tenant URL and Secret Token ready.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'superops_scim_app',
                'title' => 'Create SuperOps SCIM app in Entra',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimAppComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $scimTokensComplete,
            ], OnboardingManual::simple(
                'https://portal.azure.com — customer tenant',
                [
                    'Stay in the **'.$client->name.'** directory.',
                    'Go to **Enterprise applications → New application → Create your own application**.',
                    'Name it **'.$appName.'**. Choose non-gallery → **Create**.',
                    'Open **'.$appName.'** → left menu **Manage → Provisioning**.',
                    'If you see Get started, click **Connect your application**.',
                    'Set Provisioning Mode to **Automatic**.',
                    'Expand **Admin Credentials**.',
                    'Authentication method: **Bearer Authentication**.',
                    'Paste SuperOps **Tenant URL** into Tenant URL.',
                    'Paste SuperOps **Secret Token** into Secret Token.',
                    'Click **Test Connection**. It must succeed.',
                    'Click **Save**.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Test Connection succeeded and Provisioning is saved.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'superops_scim_provisioning',
                'title' => $usesGroupScim
                    ? 'Azure SCIM mappings + assign group + start'
                    : 'Azure SCIM mappings + copy Application ID + start',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimProvisioningComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $scimAppComplete,
            ], $this->scimProvisioningGuide($client->name, $groupName, $appName, $usesGroupScim)),

            $this->withManual([
                'key' => 'superops_client_sso_configured',
                'title' => 'Customer Accepts SuperOps login',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $ssoComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], $this->superOpsClientSsoGuide($client, $groupName, $usesGroupScim)),

            $this->withManual([
                'key' => 'portal_sync_configured',
                'title' => 'Turn on portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $syncConfigured,
                'manual' => false,
                'auto_detected' => $syncConfigured,
                'blocked' => ! $entraTenantSaved || ! $scimProvisioningComplete,
            ], OnboardingManual::simple(
                'This page — left column under Microsoft Entra sync',
                array_values(array_filter([
                    'Scroll to **Microsoft Entra sync** on the left.',
                    'Confirm **Entra tenant ID** and **Entra group ID** are filled.',
                    $usesGroupScim
                        ? null
                        : 'Confirm **SuperOps Application (client) ID** is filled (from the previous SCIM step).',
                    'Tick **Entra sync enabled**.',
                    'Click **Save client** (left).',
                ])),
                verify: [
                    'After save, **Dry run sync** and **Sync now** buttons appear under the left form.',
                ],
                notes: $syncEnabledGlobally
                    ? []
                    : ['If those buttons are missing after save, stop and message Tom.'],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'portal_sync_run',
                'title' => 'Run Dry run then Sync now',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $portalSyncRunComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ], OnboardingManual::simple(
                'This page — left column buttons under the form',
                [
                    'Click **Dry run sync** (left). Read the message at the top of the page.',
                    'If it is green and looks right, click **Sync now** (left).',
                    'Wait 1–2 minutes, then refresh this page.',
                    'Check **Last synced** on the left.',
                    'Quick checks: Admin → Users shows this client’s people; SuperOps Requesters emails start to match.',
                    'If Last synced is present, this step is Done. If you already clicked Sync now, tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Last synced shows on the left, or you ticked this step after Sync now.',
                ],
                notes: [
                    'Dry run first. Sync now applies the changes.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'login_tested',
                'title' => 'Test as a customer user',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $loginTested,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $portalSyncRunComplete,
            ], OnboardingManual::simple(
                'Private / incognito browser',
                [
                    'Open a private window (not your On IT technician session).',
                    'Go to https://app.onit.ltd/login.',
                    'Sign in with Microsoft using a **customer work email** from Admin → Users.',
                    'Confirm the dashboard loads.',
                    'Click the **SuperOps** tile. It must open as a requester (not technician).',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Customer email can open the portal and SuperOps as a requester.',
                ],
                notes: [
                    'Do not test with tom.ashby@onit.ltd or other On IT staff accounts.',
                ],
                sectionTitle: 'Do this',
            )),

            $this->withManual([
                'key' => 'handed_off',
                'title' => 'Hand off to the customer',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => (bool) ($checklist['handed_off'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $loginTested,
            ], OnboardingManual::simple(
                'Email or ticket to the customer contact',
                [
                    'Send them: go to https://app.onit.ltd and sign in with Microsoft using their work email.',
                    'They must be a licensed M365 user (shared mailboxes cannot sign in).',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Customer has been told how to sign in.',
                ],
                sectionTitle: 'Do this',
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}  $guide
     * @return array<string, mixed>
     */
    private function withManual(array $step, array $guide): array
    {
        $step['guide'] = $guide;
        $step['instructions'] = OnboardingManual::flatten($guide);

        return $step;
    }

    private function normalizeEntraLicenseTier(?string $tier): string
    {
        return in_array($tier, self::ENTRA_LICENSE_TIERS, true)
            ? $tier
            : self::ENTRA_LICENSE_FREE;
    }

    private function usesEntraGroupScim(string $tier): bool
    {
        return $tier === self::ENTRA_LICENSE_P1;
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function scimProvisioningGuide(string $clientName, string $groupName, string $appName, bool $usesGroupScim): array
    {
        $mappingSteps = [
            'Stay signed into Azure as the **'.$clientName.'** directory (top-right — not On IT).',
            'Open **Enterprise applications → '.$appName.'**.',
            'Left menu → expand **Manage** → **Attribute mapping** → open **Provision Microsoft Entra ID Users**.',
            'Set **name.givenName** = Mapping type **Direct** → Source attribute **givenName**.',
            'Set **name.familyName** = Mapping type **Direct** → Source attribute **extensionAttribute1** → Default if null: **surname**.',
            'Set **name.formatted** = Mapping type **Direct** → Source attribute **displayName**.',
            'Click **Save**.',
            'Check App role: **Microsoft Entra ID → App registrations → All applications → '.$appName.' → App roles**. If there is no role with Value **User**, Create app role: Display name User, Allowed member types Users/Groups, Value User, Enable this app role → Save.',
        ];

        $finalSteps = $usesGroupScim
            ? [
                'Back to **Enterprise applications → '.$appName.' → Manage → Users and groups → Add user/group**.',
                'Choose **Groups**, select **'.$groupName.'**, click **Assign** (once only).',
                'Open **Manage → Provisioning** → toolbar **Start provisioning**.',
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ]
            : [
                'Now copy the Application ID the portal needs on Entra ID Free:',
                'Go to **Microsoft Entra ID → App registrations → All applications**.',
                'Search for **'.$appName.'** and open it.',
                'On **Overview**, copy **Application (client) ID** — the long GUID under that exact label.',
                'Do **not** copy **Object ID** on the same Overview page — that breaks sync.',
                'On this portal page (left column), paste into **SuperOps Application (client) ID**.',
                'Click **Save client** (left).',
                'Back in Azure: **Enterprise applications → '.$appName.' → Manage → Provisioning → Start provisioning**.',
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ];

        return OnboardingManual::simple(
            'https://portal.azure.com — customer tenant — '.$appName,
            array_merge($mappingSteps, $finalSteps),
            verify: [
                $usesGroupScim
                    ? 'Group '.$groupName.' is assigned on the SCIM app and provisioning has started.'
                    : 'Left column **SuperOps Application (client) ID** is filled with Application (client) ID (not Object ID), and provisioning has started.',
            ],
            notes: [
                $usesGroupScim
                    ? 'Entra ID P1: the Portal group is assigned once in Azure. Later Sync now keeps membership updated.'
                    : 'Entra ID Free cannot assign a security group to an enterprise app. The portal uses **SuperOps Application (client) ID** instead — so you must copy it from App registrations Overview.',
            ],
            sectionTitle: 'Do this',
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;
        $assignStep = $usesGroupScim
            ? 'In Azure (still the **'.$clientName.'** directory): **Enterprise applications → SuperOps Requester SSO (On IT) → Manage → Users and groups → Add user/group** → Groups → assign **'.$groupName.'**.'
            : 'In Azure (still the **'.$clientName.'** directory): **Enterprise applications → SuperOps Requester SSO (On IT) → Manage → Users and groups → Add user/group** → assign the customer requester **users** (Free cannot assign a security group here).';

        return OnboardingManual::simple(
            'Start with the orange button above — then Azure customer tenant',
            [
                'Click **Open Microsoft Accept page** above.',
                'Sign in as a **'.$clientName.' Global Admin** — not an @onit.ltd account.',
                'Click **Accept**.',
                'Open https://portal.azure.com and confirm directory (top-right) is still **'.$clientName.'**.',
                $assignStep,
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ],
            verify: [
                'In the customer Azure tenant, **SuperOps Requester SSO (On IT)** exists under Enterprise applications and has users/groups assigned.',
            ],
            notes: [
                'Do not open SuperOps Client SSO. After Accept, finish the Azure Users and groups assignment in the customer tenant.',
            ],
            sectionTitle: 'Do this',
        );
    }

    /**
     * @return array{complete: int, total: int, percent: int}
     */
    public function progress(Client $client): array
    {
        $steps = $this->steps($client);
        $complete = collect($steps)->where('complete', true)->count();
        $total = count($steps);

        return [
            'complete' => $complete,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($complete / $total) * 100) : 0,
        ];
    }

    /**
     * @param  array<string, bool>  $checkpoints
     */
    public function updateChecklist(Client $client, array $checkpoints): void
    {
        $current = $client->onboarding_checklist ?? [];

        foreach ($checkpoints as $key => $value) {
            if (! in_array($key, self::MANUAL_CHECKPOINTS, true)) {
                continue;
            }

            $current[$key] = (bool) $value;
        }

        // Keep legacy SCIM key in sync when all new SCIM substeps are complete.
        if (
            ($current['superops_scim_tokens'] ?? false)
            && ($current['superops_scim_app'] ?? false)
            && ($current['superops_scim_provisioning'] ?? false)
        ) {
            $current['superops_scim_configured'] = true;
        }

        $client->update(['onboarding_checklist' => $current]);
    }

    /**
     * Persist checklist ticks that match saved client fields.
     */
    public function syncAutoCheckpointsFromClient(Client $client): void
    {
        $current = $client->onboarding_checklist ?? [];
        $changed = false;

        if (filled($client->entra_group_id) && filled($client->entra_tenant_id)) {
            $changed = ($current['entra_group_created'] ?? false) !== true;
            $current['entra_group_created'] = true;
        }

        if ($client->entra_synced_at !== null && ($current['portal_sync_run'] ?? false) !== true) {
            $changed = true;
            $current['portal_sync_run'] = true;
        }

        if ($changed) {
            $client->update(['onboarding_checklist' => $current]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldHelps(?Client $client = null): array
    {
        $clientName = $client?->name ?? '{Company name}';
        $groupName = 'On IT Portal - '.$clientName;
        $usesGroupScim = $this->usesEntraGroupScim($this->normalizeEntraLicenseTier($client?->entra_license_tier));

        return [
            'superops_account_id' => [
                'SuperOps → Clients → open the customer → copy Account ID from the URL after /client/.',
                'Paste here → Save client.',
            ],
            'pax8_company_id' => [
                'Only if they use the Pax8 tile.',
                'Pax8 → Companies → copy company UUID → paste here and tick Pax8 access.',
            ],
            'entra_license_tier' => [
                'Set this before you create the Portal group.',
                'Free and P1 change the last SCIM step only.',
            ],
            'entra_tenant_id' => [
                'Azure (customer tenant) → Microsoft Entra ID → Overview → Tenant ID.',
                'Needed for the Accept buttons.',
            ],
            'entra_group_id' => [
                'Create group '.$groupName.' empty, copy Object ID, paste here.',
                'Sync fills members later — do not add people by hand.',
            ],
            'entra_superops_app_id' => $usesGroupScim
                ? [
                    'Optional on P1 when the Portal group is assigned to the SuperOps SCIM app.',
                ]
                : [
                    'Required on Free.',
                    'App registrations → SuperOps - '.$clientName.' → Overview → Application (client) ID (not Object ID).',
                ],
            'entra_sync_enabled' => [
                'Tick this after Entra IDs are saved, then Save client.',
                'Then use Dry run sync and Sync now on the left.',
            ],
        ];
    }
}
