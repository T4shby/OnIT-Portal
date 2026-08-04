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
    public const RESPONSIBLE_ON_IT_PORTAL = 'On IT · portal / SuperOps';

    public const RESPONSIBLE_ON_IT_CUSTOMER_ENTRA = 'On IT · customer Entra';

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
        if (! $client->exists) {
            return null;
        }

        $appClientId = config('services.entra_sync.client_id');

        if (! filled($appClientId)) {
            return null;
        }

        $redirectUri = config('services.azure.redirect');
        $state = \App\Support\AdminConsentState::encode($client->id);

        // Prefer known customer tenant; otherwise organizations so Accept works before tenant ID is known.
        $tenantSegment = filled($client->entra_tenant_id)
            ? $client->entra_tenant_id
            : 'organizations';

        $query = http_build_query(array_filter([
            'client_id' => $appClientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]));

        return sprintf(
            'https://login.microsoftonline.com/%s/adminconsent?%s',
            $tenantSegment,
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
        $ssoAppName = 'SuperOps Requester SSO - '.$client->name;
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
        $scimAppComplete = $legacyScimComplete
            || (bool) ($checklist['superops_scim_app'] ?? false)
            || filled($client->entra_superops_app_id);
        $scimProvisioningComplete = $legacyScimComplete || (bool) ($checklist['superops_scim_provisioning'] ?? false);

        $ssoComplete = (bool) ($checklist['superops_client_sso_configured'] ?? false);
        $portalSyncRunComplete = $syncRun || (bool) ($checklist['portal_sync_run'] ?? false);
        $loginTested = (bool) ($checklist['login_tested'] ?? false);

        $connectWhere = 'This checklist → orange Connect Microsoft tenant (private browser / GDAP)';

        return [
            $this->withManual([
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $superopsLinked,
                'manual' => false,
                'auto_detected' => $superopsLinked,
                'blocked' => false,
            ], OnboardingManual::build(
                sections: [
                    OnboardingManual::section(
                        'Get the Account ID from SuperOps',
                        $superOpsUrl.' → Clients',
                        [
                            'Open '.$superOpsUrl.' and sign in as an On IT technician (MSP console).',
                            'Top or left menu → **Clients**.',
                            'Search and open **'.$client->name.'**. If missing, create that SuperOps client first.',
                            'Look at the browser address bar. Copy the long number after `/client/` — that is the SuperOps Account ID.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Paste it into this portal',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left column)',
                        [
                            'Stay on this portal Edit Client page (or reopen it).',
                            'Left column → field **SuperOps Account ID** → paste the number.',
                            'Click orange **Save client** (left).',
                        ],
                    ),
                ],
                verify: [
                    'This step turns Done automatically when SuperOps Account ID is saved.',
                ],
            )),

            $this->withManual([
                'key' => 'pax8_linked',
                'title' => 'Link Pax8 (or skip)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $pax8Configured,
                'manual' => false,
                'auto_detected' => $pax8Configured,
                'blocked' => false,
            ], OnboardingManual::build(
                notes: [
                    'Skip if they do not use Pax8 — leave Company ID blank and access unticked.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Copy Company UUID from Pax8 (only if they use Pax8)',
                        'https://app.pax8.com → Companies',
                        [
                            'Open https://app.pax8.com and sign in.',
                            'Go to **Companies** → open this customer.',
                            'Copy the company **UUID** from the browser URL or company profile page.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Save on this portal (only if they use Pax8)',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left column)',
                        [
                            'Left column → **Pax8 Company ID** → paste the UUID.',
                            'Tick **Pax8 access**.',
                            'Click orange **Save client** (left).',
                        ],
                    ),
                ],
                verify: [
                    'Pax8 unused = Done, or Pax8 Company ID is saved.',
                ],
            )),

            $this->withManual([
                'key' => 'entra_group_created',
                'title' => 'Connect Microsoft tenant (IDs + group + licence)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraTenantSaved && $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ], OnboardingManual::build(
                notes: [
                    'Automatic: one Connect / Accept writes tenant ID, licence tier, portal group and Entra app IDs. No hand-copy of Object ID.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Connect Microsoft tenant (do this once)',
                        $connectWhere,
                        [
                            'Open a **private/incognito** browser (not your everyday On IT profile).',
                            'On this checklist (step 03 or 04) click orange **Connect Microsoft tenant**.',
                            'Sign in with On IT **GDAP** so Microsoft shows **'.$client->name.'** — log straight into their tenant, not On IT first.',
                            'Click **Accept** on the Portal Graph permissions page.',
                            'You return here; tenant ID, licence (Free/P1), group **'.$groupName.'**, and SuperOps Entra app IDs are saved on the left automatically.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Only if Connect is unavailable',
                        'This portal left column',
                        [
                            'If Accept failed or Graph lacks Group.ReadWrite.All / Application.ReadWrite.All, create group and paste Tenant / Group IDs manually (legacy).',
                            'Left column → **Entra license tier**, **Entra tenant ID**, **Entra group ID** → **Save client**.',
                        ],
                    ),
                ],
                verify: [
                    'Left column shows Tenant ID + Group ID (and licence tier). This step turns Done automatically.',
                ],
            )),

            $this->withManual([
                'key' => 'entra_admin_consent_granted',
                'title' => 'Accept Portal Graph (starts auto setup)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun || ($adminConsentComplete && $entraTenantSaved),
                'blocked' => ! $superopsLinked,
            ], OnboardingManual::build(
                notes: [
                    'Same Connect button as step 03 — Accept once, then the portal bootstraps Entra via Graph.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Accept with the orange button',
                        $connectWhere,
                        [
                            'Click orange **Connect Microsoft tenant** (or **Open Portal Accept**) above.',
                            'Private browser · GDAP for **'.$client->name.'** · confirm Microsoft shows that tenant · **Accept**.',
                            'After Accept the portal writes tenant, licence, group, SCIM app and Client SSO app shells where Graph allows.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Verify if something failed',
                        $this->customerAzureWhere($client->name, 'Manage → Enterprise applications → OnIT Portal for Portals'),
                        [
                            ...$this->openCustomerAzureSteps($client->name),
                            'Azure top search → **Microsoft Entra ID** → open it.',
                            'Left **Manage** → **Enterprise applications** → **All applications**.',
                            'Search **OnIT Portal for Portals** → **Permissions** → Granted for **'.$client->name.'**.',
                        ],
                    ),
                ],
                verify: [
                    'Accept succeeded and left-side Entra fields are populated (or flash shows Graph permission errors to fix once).',
                ],
            )),

            $this->withManual([
                'key' => 'superops_scim_tokens',
                'title' => 'Get SuperOps SCIM tokens',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimTokensComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], OnboardingManual::build(
                sections: [
                    OnboardingManual::section(
                        'Generate tokens in SuperOps',
                        $superOpsUrl.' → Settings / Integrations → Microsoft Entra ID',
                        [
                            'Open '.$superOpsUrl.' (SuperOps MSP / technician console).',
                            'Open **Settings** (gear) or left nav → **Integrations**.',
                            'Open **Microsoft Entra ID**.',
                            'Click **Generate Tokens**.',
                            'In the client picker, select **'.$client->name.'** (same SuperOps client as step 01).',
                            'Copy **Tenant URL** into Notepad. This is a SuperOps SCIM URL — **not** the Azure Tenant ID from step 03.',
                            'Copy **Secret Token** (also labelled **Auth Token**) into Notepad.',
                            'Keep both — the next Azure step pastes them.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Mark complete on this portal',
                        'https://app.onit.ltd → this checklist (right)',
                        [
                            'Tick **Mark this step complete** — it saves automatically (or press **Save checklist** at the bottom).',
                            'Do **not** use **Save client** for this tick — that only saves SuperOps / Entra IDs on the left.',
                        ],
                    ),
                ],
                verify: [
                    'You have Tenant URL + Secret Token for '.$client->name.' ready to paste.',
                    'Step 05 shows **Done** after the tick saves.',
                ],
            )),

            $this->withManual([
                'key' => 'superops_scim_app',
                'title' => 'Create SuperOps SCIM app in Entra',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimAppComplete,
                'manual' => true,
                'auto_detected' => filled($client->entra_superops_app_id),
                'blocked' => ! $scimTokensComplete && ! filled($client->entra_superops_app_id),
            ], OnboardingManual::build(
                notes: [
                    'Automatic after Connect Microsoft: portal creates app **'.$appName.'** with App role Value User and saves Application (client) ID.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Usually already Done after Connect',
                        'Left column → SuperOps Application (client) ID',
                        [
                            'After **Connect Microsoft tenant**, confirm **SuperOps Application (client) ID** is filled on the left.',
                            'If still empty, fix Graph Application.ReadWrite.All on OnIT Portal for Portals, re-Accept, then use **Re-run Entra bootstrap** on this page.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Only if the app is missing — create manually',
                        $this->customerAzureWhere($client->name, 'Manage → Enterprise applications'),
                        [
                            ...$this->openCustomerAzureSteps($client->name),
                            'Azure top search → **Microsoft Entra ID** → open it.',
                            'Left **Manage** → **Enterprise applications** → **New application** → non-gallery → name **'.$appName.'**.',
                            'App roles → Role **User** (Value **User**).',
                        ],
                    ),
                ],
                verify: [
                    'SuperOps Application (client) ID is saved (step turns Done automatically when the ID is present).',
                ],
            )),

            $this->withManual([
                'key' => 'superops_scim_provisioning',
                'title' => $usesGroupScim
                    ? 'Apply SCIM tokens + start provisioning'
                    : 'Apply SCIM tokens + start (Free)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimProvisioningComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $scimAppComplete,
            ], $this->scimProvisioningGuide($client->name, $groupName, $appName, $usesGroupScim)),

            $this->withManual([
                'key' => 'superops_client_sso_configured',
                'title' => 'Configure SuperOps Client SSO (SAML)',
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
            ], OnboardingManual::build(
                notes: $syncEnabledGlobally
                    ? []
                    : ['If Dry run / Sync now are missing after save, message Tom.'],
                sections: [
                    OnboardingManual::section(
                        'Enable sync on this portal',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' → left → Microsoft Entra sync',
                        array_values(array_filter([
                            'Stay on **Admin → Clients → Edit '.$client->name.'**.',
                            'Scroll the left column to **Microsoft Entra sync**.',
                            'Confirm **Entra tenant ID** is filled.',
                            'Confirm **Entra group ID** is filled.',
                            $usesGroupScim
                                ? null
                                : 'Confirm **SuperOps Application (client) ID** is filled (from the previous Azure App registrations step).',
                            'Tick **Entra sync enabled**.',
                            'Click orange **Save client** (left).',
                            'After reload, look under the left form for orange buttons **Dry run sync** and **Sync now**.',
                        ])),
                    ),
                ],
                verify: [
                    'Entra sync enabled stays ticked and Dry run / Sync now buttons are visible on the left.',
                ],
            )),

            $this->withManual([
                'key' => 'portal_sync_run',
                'title' => 'Run Dry run then Sync now',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $portalSyncRunComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ], OnboardingManual::build(
                notes: [
                    'Dry run first, then Sync now.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Run sync on this portal',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left buttons)',
                        [
                            'On this Edit Client page, scroll left to buttons **Dry run sync** and **Sync now**.',
                            'Click **Dry run sync**. Read the banner at the **top** of the page (Created / Updated / SuperOps group / SuperOps app lines).',
                            'If the banner is red, fix the message (often redo step 04 Accept, or App role Value **User** on the SCIM app) then dry run again.',
                            'When the dry run looks correct, click **Sync now**.',
                            'Wait 1–2 minutes. Refresh this Edit page.',
                            'Under Microsoft Entra sync on the left, confirm **Last synced** has a time.',
                            'Verify portal users: top menu **Admin → Users** → filter / open this client — licensed people appear.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Verify in customer Azure',
                        $this->customerAzureWhere($client->name, 'Manage → Groups / Enterprise applications'),
                        array_values(array_filter([
                            ...$this->openCustomerAzureSteps($client->name),
                            'Azure top search → **Microsoft Entra ID** → open it.',
                            'Left **Manage** → **Groups** → **'.$groupName.'** → **Members** — members listed (portal added them).',
                            $usesGroupScim
                                ? null
                                : 'Left **Manage** → **Enterprise applications** → **'.$appName.'** → **Users and groups** — users listed after sync (Free path).',
                            $usesGroupScim
                                ? null
                                : 'Enterprise applications → **'.$ssoAppName.'** → **Users and groups** — every active licensed user listed after Sync now (Free path; portal assigned them automatically).',
                            'Enterprise applications → **'.$appName.'** → **Provisioning** → **Provisioning logs** — Updates appear after a few minutes.',
                        ])),
                    ),
                    OnboardingManual::section(
                        'Verify in SuperOps',
                        $superOpsUrl.' → Clients → '.$client->name.' → Requesters',
                        [
                            'Open '.$superOpsUrl.'.',
                            '**Clients** → **'.$client->name.'** → **Requesters**.',
                            'Emails should match portal users; last names often show (User Mailbox) / (Shared Mailbox).',
                        ],
                    ),
                    OnboardingManual::section(
                        'Mark complete if needed',
                        'https://app.onit.ltd → this checklist (right)',
                        [
                            'If Last synced is set, this step is Done. If Sync now already ran, tick **Mark this step complete** — saves automatically (or **Save checklist**).',
                        ],
                    ),
                ],
                verify: [
                    'Last synced is set; portal users, Azure group, and SuperOps requesters look right.',
                ],
            )),

            $this->withManual([
                'key' => 'login_tested',
                'title' => 'Test as a customer user',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $loginTested,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $portalSyncRunComplete,
            ], OnboardingManual::build(
                notes: [
                    'Test with a customer work email — not an On IT account.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Sign in as the customer',
                        'Private/incognito browser → https://app.onit.ltd/login',
                        [
                            'Open a private/incognito window (not your On IT technician Chrome profile).',
                            'Go to https://app.onit.ltd/login.',
                            'Click **Sign in with Microsoft**.',
                            'Pick a **customer work email** that exists under **Admin → Users** for this client (not tom.ashby@onit.ltd).',
                            'Confirm the portal dashboard loads for that customer.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Open SuperOps from the dashboard',
                        'Customer dashboard → SuperOps tile → requester portal',
                        [
                            'Click the **SuperOps** tile on the dashboard.',
                            'It must open the requester portal (not technician role chooser).',
                            'If Microsoft prompts again, sign in with the same customer email.',
                            'Tick **Mark this step complete** — saves automatically (or **Save checklist**). Not **Save client**.',
                        ],
                    ),
                ],
                verify: [
                    'Customer email can open app.onit.ltd and SuperOps as a requester.',
                ],
            )),

            $this->withManual([
                'key' => 'handed_off',
                'title' => 'Hand off to the customer',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => (bool) ($checklist['handed_off'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $loginTested,
            ], OnboardingManual::build(
                sections: [
                    OnboardingManual::section(
                        'Tell the customer how to sign in',
                        'Email / ticket to the customer contact',
                        [
                            'Email or ticket the customer contact.',
                            'Tell them: open https://app.onit.ltd → Sign in with Microsoft → use their work email.',
                            'Tell them they must be a licensed M365 user (shared mailboxes cannot sign in).',
                            'Tick **Mark this step complete** — saves automatically (or **Save checklist**). Not **Save client**.',
                        ],
                    ),
                ],
                verify: [
                    'Customer has been told how to sign in.',
                ],
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
        return OnboardingManual::build(
            notes: [
                'Use the form at the top of this step (not the left form). Apply SuperOps Tenant URL + Secret → portal starts Entra SCIM.',
                'Connect Microsoft already creates **'.$appName.'**, App role User, and P1 group assignment when possible.',
                $usesGroupScim
                    ? 'P1: group assign is usually already Done after Connect.'
                    : 'Free: Application (client) ID is usually already on the left after Connect.',
            ],
            sections: [
                OnboardingManual::section(
                    'Apply tokens on this step (preferred)',
                    'This checklist step → form at the top',
                    [
                        'Generate tokens in SuperOps (step 05) if you have not already.',
                        'At the top of this step: paste SuperOps **Tenant URL** and **Secret Token**.',
                        'Click **Apply SCIM credentials + start**.',
                        'Portal writes credentials into Entra app **'.$appName.'** and starts provisioning.',
                        'This step marks complete when Apply succeeds.',
                    ],
                ),
                OnboardingManual::section(
                    'Only if Apply fails — paste in Azure',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Provisioning'),
                    [
                        'Finish **step 05** first (SuperOps Generate Tokens) if you have not already.',
                        ...$this->openCustomerAzureSteps($clientName),
                        'Azure top search → **Microsoft Entra ID** → open it.',
                        'Left **Manage** → **Enterprise applications** → open **'.$appName.'**.',
                        'App **Manage** → **Provisioning** → mode **Automatic**.',
                        'Expand **Admin Credentials** → paste SuperOps Tenant URL + Secret Token → **Test Connection** → **Save**.',
                        'Click toolbar **Start provisioning**.',
                    ],
                ),
                OnboardingManual::section(
                    'Edit SCIM attribute mappings (once per client if names wrong)',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Provisioning'),
                    [
                        'Still on **'.$appName.'** → **Provisioning**.',
                        'Under Provisioning, open **Attribute mapping** (sometimes labelled **Mappings**).',
                        'Click **Provision Microsoft Entra ID Users**.',
                        'For **name.givenName**: Mapping type **Direct** → Source **givenName** → Always → OK.',
                        'For **name.familyName**: Mapping type **Direct** → Source **extensionAttribute1** → Default if null **[surname]** → Always → OK.',
                        'For **name.formatted**: Mapping type **Direct** → Source **displayName** → Always → OK.',
                        'Save mappings at the top of the attribute mapping page.',
                    ],
                ),
                OnboardingManual::section(
                    'App role Value User (usually automatic)',
                    $this->customerAzureWhere($clientName, 'Manage → App registrations → '.$appName.' → App roles'),
                    [
                        'Connect Microsoft creates App role **User** automatically. Skip unless Sync now errors about app roles.',
                        'If missing: App registrations → **'.$appName.'** → **App roles** → Value **User** enabled.',
                    ],
                ),
                $usesGroupScim
                    ? OnboardingManual::section(
                        'Assign Portal group to the enterprise app',
                        $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Users and groups'),
                        [
                            'Usually Done after Connect. If not: **Users and groups** → Add → **'.$groupName.'** → Assign.',
                        ],
                    )
                    : OnboardingManual::section(
                        'Application (client) ID on portal (Entra Free)',
                        'https://app.onit.ltd Edit Client left',
                        [
                            'Usually already filled after Connect as **SCIM Application (client) ID**. Confirm it matches App registrations → **'.$appName.'**.',
                        ],
                    ),
            ],
            verify: [
                'Apply SCIM succeeded (or Azure Provisioning shows On), step Done.',
            ],
        );
    }

    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;
        $appName = 'SuperOps Requester SSO - '.$clientName;

        $assignSection = $usesGroupScim
            ? OnboardingManual::section(
                'Assign Portal group in customer Azure',
                $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Users and groups'),
                [
                    'Usually already Done after Connect. If not: still in tenant **'.$clientName.'**.',
                    'Entra left **Manage** → **Enterprise applications** → open **'.$appName.'**.',
                    'App left menu → **Manage** → **Users and groups** → **Add user/group**.',
                    'Groups → select **'.$groupName.'** → **Select** → **Assign**.',
                ],
            )
            : OnboardingManual::section(
                'Save the Client SSO app ID for automatic assignment (Free)',
                $this->customerAzureWhere($clientName, 'Manage → App registrations → '.$appName.' → Overview').'; then portal left column',
                [
                    'Usually already on the left after Connect (**Client SSO Application (client) ID**).',
                    'If empty: still in tenant **'.$clientName.'**.',
                    'Entra left **Manage** → **App registrations** → **'.$appName.'** → **Overview**.',
                    'Copy **Application (client) ID** — not Object ID.',
                    'Portal left column → **Client SSO Application (client) ID** → paste → **Save client**.',
                    'Do not add users by hand — **Sync now** assigns them.',
                ],
            );

        return OnboardingManual::build(
            notes: [
                'Use the form at the top of this step. SuperOps Entity ID + Consumer URL → Configure SAML → copy Login URL + cert into SuperOps. Azure only if that fails.',
                'Connect Microsoft creates the Entra app shell **'.$appName.'** and saves its Application ID.',
            ],
            sections: [
                OnboardingManual::section(
                    'Generate SuperOps Entity ID + Consumer URL',
                    'SuperOps MSP → Settings → Requester Login → SSO Protected → Client SSO',
                    [
                        'Click **+ Configuration** (or open existing **'.$clientName.'** config).',
                        'Select client **'.$clientName.'**. Generate values if needed.',
                        'Copy full HTTPS **Entity ID** and **Consumer Service URL**. Keep SuperOps open.',
                    ],
                ),
                OnboardingManual::section(
                    'Configure SAML on this step (preferred)',
                    'This checklist step → form at the top',
                    [
                        'Paste SuperOps **Entity ID** and **Consumer Service URL** into the form above.',
                        'Click **Configure SAML in Entra**.',
                        'Copy the **IDP Login URL** and **Certificate** shown into SuperOps step 3 → **Save / Enable**.',
                        'Do not reuse another customer’s Login URL or certificate.',
                    ],
                ),
                OnboardingManual::section(
                    'Only if Configure SAML fails — Azure manually',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName),
                    [
                        ...$this->openCustomerAzureSteps($clientName),
                        'Enterprise applications → **'.$appName.'** → Single sign-on → SAML.',
                        'Identifier = SuperOps Entity ID; Reply URL = Consumer Service URL → Save.',
                        'Claims: email / firstname / lastname → copy Login URL + Base64 cert into SuperOps.',
                    ],
                ),
                $assignSection,
                OnboardingManual::section(
                    'Mark complete on this portal',
                    'This checklist step',
                    [
                        'Configure SAML success marks this step; otherwise tick **Mark this step complete** after SuperOps Save.',
                    ],
                ),
            ],
            verify: [
                $usesGroupScim
                    ? 'SuperOps Client SSO enabled for '.$clientName.'; portal/group assignment ready.'
                    : 'SuperOps Client SSO enabled; Client SSO Application ID on portal for Sync now assignment.',
            ],
        );
    }

    /**
     * Open the customer's Entra tenant for GDAP work.
     * Never "sign into On IT then switch" — private browser, land on the customer tenant.
     *
     * @return list<string>
     */
    private function openCustomerAzureSteps(string $clientName): array
    {
        return [
            'Open a **private/incognito** browser (not your normal On IT technician browser profile).',
            'Go to https://portal.azure.com and sign in with On IT **GDAP** so you land in tenant **'.$clientName.'** directly.',
            'Do **not** sign into **On IT Technology Partners LTD** first and switch directories — always open the customer tenant straight away.',
            'Confirm top-right shows **'.$clientName.'** before you continue.',
        ];
    }

    private function customerAzureWhere(string $clientName, string $afterEntra): string
    {
        return 'Private browser → portal.azure.com (**'.$clientName.'**) → Microsoft Entra ID → '.$afterEntra;
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
                'SuperOps → Clients → open customer → copy the number after /client/.',
            ],
            'pax8_company_id' => [
                'Optional. Pax8 → Companies → copy company UUID.',
            ],
            'huntress_organization_id' => [
                'Optional. Huntress → Organizations → open customer → copy the organization ID.',
            ],
            'dropsuite_organization_id' => [
                'Optional. Dropsuite / NinjaOne SaaS Backup → Organizations → copy the organization ID for dashboard backup metrics.',
            ],
            'entra_license_tier' => [
                'Match the customer Entra Overview → License. Free vs P1 changes steps 07–08.',
            ],
            'entra_tenant_id' => [
                'Private browser → customer tenant → Microsoft Entra ID → Overview → Tenant ID.',
            ],
            'entra_group_id' => [
                'Private browser → customer Entra → Manage → Groups → empty group '.$groupName.' → Object ID. Sync fills members.',
            ],
            'entra_superops_app_id' => $usesGroupScim
                ? [
                    'Optional on P1 if the Portal group is already assigned to the SCIM app.',
                ]
                : [
                    'App registrations → SuperOps - '.$clientName.' → Overview → Application (client) ID.',
                ],
            'entra_superops_sso_app_id' => $usesGroupScim
                ? [
                    'App registrations → SuperOps Requester SSO - '.$clientName.' → Application (client) ID.',
                ]
                : [
                    'Required on Free. App registrations → SuperOps Requester SSO - '.$clientName.' → Application (client) ID.',
                ],
            'entra_sync_enabled' => [
                'Tick after IDs are saved, then use Dry run / Sync now below.',
            ],
        ];
    }
}
