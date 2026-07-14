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
                'https://portal.azure.com — customer Microsoft Entra (not On IT)',
                [
                    'On the left of this portal page, set **Customer Entra license tier** to **Entra ID Free** or **Entra ID P1 or higher** to match what the customer pays for.',
                    'Open https://portal.azure.com in a new tab.',
                    'Top-right directory switcher → select **'.$client->name.'** (customer). Do not stay in On IT Technology Partners.',
                    'Left menu → **Microsoft Entra ID**.',
                    'Left menu → **Manage → Groups → All groups → New group**.',
                    'Group type: **Security**.',
                    'Group name: type exactly **'.$groupName.'**.',
                    'Membership type: **Assigned**.',
                    'Owners / Members: leave empty (do not add anyone).',
                    'Click **Create**. Wait until the group appears in the list, then open it.',
                    'On the group **Overview**, copy **Object ID** (GUID).',
                    'Left menu → **Microsoft Entra ID → Overview**.',
                    'Copy **Tenant ID** (GUID).',
                    'Back on this portal page (left): paste Tenant ID into **Entra tenant ID**.',
                    'Paste the group Object ID into **Entra group ID**.',
                    'Click **Save client** (left).',
                ],
                verify: [
                    'Left column shows both Entra IDs saved and this step shows Done.',
                ],
                notes: [
                    'Members stay empty on purpose. Sync later fills the group.',
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
                    'Use a private/incognito window if you are already signed into On IT Azure.',
                    'Sign in as a **'.$client->name.' Global Admin** — not an @onit.ltd account.',
                    'Check the page shows tenant **'.$client->name.'**, not On IT Technology Partners.',
                    'Click **Accept**.',
                    'Optional check: Azure (customer directory) → **Enterprise applications → OnIT Portal for Portals → Permissions** shows Granted.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Accept succeeded for '.$client->name.'.',
                ],
                notes: [
                    'This Accept is for portal Graph sync only. SuperOps login Accept is a later step.',
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
                $superOpsUrl.' — SuperOps MSP console',
                [
                    'Sign in to SuperOps MSP (technician) console.',
                    'Go to **Integrations**.',
                    'Open **Microsoft Entra ID**.',
                    'Click **Generate Tokens**.',
                    'In the client list, select **'.$client->name.'** (must be the SuperOps client you linked in step 01).',
                    'Copy **Tenant URL** — this is a SuperOps URL for SCIM, not the Azure Tenant ID.',
                    'Copy **Secret Token** / **Auth Token** (same value, different label).',
                    'Store both somewhere safe — you paste them into Azure in the next step.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'You have Tenant URL and Secret Token copied for '.$client->name.'.',
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
                'https://portal.azure.com — customer Microsoft Entra',
                [
                    'Open https://portal.azure.com.',
                    'Top-right directory switcher → **'.$client->name.'** (customer tenant).',
                    'Left menu → **Microsoft Entra ID → Enterprise applications → All applications**.',
                    'Click **New application**.',
                    'Click **Create your own application**.',
                    'Name: type exactly **'.$appName.'**.',
                    'Select **Integrate any other application you don’t find in the gallery (Non-gallery)**.',
                    'Click **Create**. Wait for the app to open.',
                    'Left menu → expand **Manage** (if collapsed) → click **Provisioning**.',
                    'If the page shows **Get started**, click **Connect your application**.',
                    'Provisioning Mode dropdown → **Automatic**.',
                    'Click the **Admin Credentials** heading to expand it (fields are hidden until expanded).',
                    'Authentication method → leave **Bearer Authentication** selected.',
                    'Tenant URL → paste the SuperOps **Tenant URL** from the previous step (not Azure Tenant ID).',
                    'Secret Token → paste the SuperOps **Secret Token / Auth Token**.',
                    'Click **Test Connection**. Wait until Azure says the connection succeeded.',
                    'If Test Connection fails: re-copy tokens from SuperOps Generate Tokens and try again. Do not continue until it succeeds.',
                    'Click toolbar **Save**.',
                    'Tick **Mark this step complete** → **Save checklist** (right).',
                ],
                verify: [
                    'Enterprise app **'.$appName.'** exists, Test Connection succeeded, Provisioning saved.',
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
            'Open https://portal.azure.com → switch directory (top-right) to **'.$clientName.'**.',
            'Go to **Microsoft Entra ID → Enterprise applications → All applications → '.$appName.'**.',
            'Left menu → expand **Manage** → **Provisioning**.',
            'Open **Attribute mapping** (or **Mappings**), then open **Provision Microsoft Entra ID Users**.',
            'Find **name.givenName** → Mapping type **Direct** → Source attribute **givenName** → Apply this mapping **Always**.',
            'Find **name.familyName** → Mapping type **Direct** → Source attribute **extensionAttribute1** → Default if null **[surname]** (or surname) → Apply **Always**.',
            'Find **name.formatted** → Mapping type **Direct** → Source attribute **displayName** → Apply **Always**.',
            'Click **Save** on the attribute mapping page.',
            'Create the App role if missing (required especially on Entra ID Free):',
            'Go to **Microsoft Entra ID → App registrations → All applications**.',
            'Search **'.$appName.'** and open it.',
            'Left menu → **App roles → Create app role**.',
            'Display name: **User**. Allowed member types: **Users/Groups**. Value: **User**. Description: **Default access for SCIM users**. Tick **Enable this app role**.',
            'Click **Apply** / **Save**. If a User role already exists and is Enabled, skip create.',
        ];

        $finalSteps = $usesGroupScim
            ? [
                'Go back to **Enterprise applications → '.$appName.'**.',
                'Left menu → **Manage → Users and groups → Add user/group**.',
                'Open the **Groups** tab → select **'.$groupName.'** → **Select** → **Assign**.',
                'Left menu → **Manage → Provisioning** → click toolbar **Start provisioning**.',
                'Confirm provisioning status shows On / started.',
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ]
            : [
                'Entra ID Free — copy Application (client) ID for the portal (do not skip):',
                'Go to **Microsoft Entra ID → App registrations → All applications**.',
                'Search **'.$appName.'** and open it.',
                'Stay on **Overview**.',
                'Copy **Application (client) ID** — long GUID under that exact label.',
                'Do **not** copy **Object ID** (different field on the same Overview page).',
                'Return to this portal Edit Client page.',
                'Left column → paste into **SuperOps Application (client) ID**.',
                'Click **Save client** (left).',
                'Back in Azure: **Enterprise applications → '.$appName.' → Manage → Provisioning → Start provisioning**.',
                'Confirm provisioning status shows On / started.',
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ];

        return OnboardingManual::simple(
            'https://portal.azure.com — customer tenant — '.$appName,
            array_merge($mappingSteps, $finalSteps),
            verify: [
                $usesGroupScim
                    ? 'Attribute mappings saved, group '.$groupName.' assigned, provisioning started.'
                    : 'Attribute mappings saved, **SuperOps Application (client) ID** pasted on the left (Application client ID, not Object ID), provisioning started.',
            ],
            notes: [
                $usesGroupScim
                    ? 'P1: assign the Portal group once in Azure. Later Sync now keeps membership updated.'
                    : 'Free: Azure will not let you assign the Portal group to the enterprise app. The portal needs Application (client) ID so Sync now can assign users for you.',
            ],
            sectionTitle: 'Do this',
        );
    }

    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;
        $assignSteps = $usesGroupScim
            ? [
                'Open https://portal.azure.com → switch directory to **'.$clientName.'**.',
                'Go to **Microsoft Entra ID → Enterprise applications → All applications**.',
                'Search **SuperOps Requester SSO (On IT)** and open it (created by the Accept).',
                'Left menu → **Manage → Users and groups → Add user/group**.',
                'Groups tab → select **'.$groupName.'** → **Select** → **Assign**.',
            ]
            : [
                'Open https://portal.azure.com → switch directory to **'.$clientName.'**.',
                'Go to **Microsoft Entra ID → Enterprise applications → All applications**.',
                'Search **SuperOps Requester SSO (On IT)** and open it (created by the Accept).',
                'Left menu → **Manage → Users and groups → Add user/group**.',
                'Users tab → add the customer requester users who must Microsoft-sign-in (Free cannot assign a security group here).',
            ];

        return OnboardingManual::simple(
            'Orange Accept button above, then customer Azure',
            array_merge([
                'Click **Open Microsoft Accept page** above.',
                'Sign in as a **'.$clientName.' Global Admin** — not an @onit.ltd account.',
                'Confirm the permissions page is for **'.$clientName.'**, not On IT.',
                'Click **Accept**.',
            ], $assignSteps, [
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ]),
            verify: [
                'Customer Azure shows **SuperOps Requester SSO (On IT)** under Enterprise applications with users/groups assigned.',
            ],
            notes: [
                'Do not open SuperOps Client SSO. Do not change certificates or Global SSO settings for this client.',
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
