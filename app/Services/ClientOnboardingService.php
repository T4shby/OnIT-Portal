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
     * MSP technician Accept URL for SuperOps Requester SSO (On IT) in the customer tenant.
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

        $redirectUri = config('services.superops.requester_sso_consent_redirect');

        $query = http_build_query(array_filter([
            'client_id' => $appClientId,
            'redirect_uri' => filled($redirectUri) ? $redirectUri : null,
            'state' => $client->exists ? \App\Support\AdminConsentState::encode($client->id) : null,
        ]));

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
                    'If this customer will **not** see a Pax8 tile: leave **Pax8 Company ID** blank and **Pax8 access** unticked — this step is already Done.',
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
                'title' => 'Create Portal group + save Entra IDs',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraTenantSaved && $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ], OnboardingManual::build(
                notes: [
                    'Leave group Members empty. Sync fills them later.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Confirm Entra licence in customer Azure',
                        'https://portal.azure.com → **'.$client->name.'** → Microsoft Entra ID → Overview',
                        [
                            'Open a new tab → https://portal.azure.com.',
                            'Top-right → directory / tenant switcher → pick **'.$client->name.'**. Do **not** stay in On IT Technology Partners LTD.',
                            'Azure search bar (top) → type **Microsoft Entra ID** → open it.',
                            'Entra left menu → **Overview**.',
                            'Read **License** (e.g. Microsoft Entra ID Free, or Microsoft Entra ID P1).',
                        ],
                    ),
                    OnboardingManual::section(
                        'Set licence tier on this portal to match',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left)',
                        [
                            'On the left, under Microsoft Entra sync / Entra fields: set **Customer Entra license tier** to **Entra ID Free** or **Entra ID P1 or higher** — must match Overview → License above.',
                            'Wrong tier breaks Free vs P1 paths later (Application ID vs group assign).',
                        ],
                    ),
                    OnboardingManual::section(
                        'Create the security group in customer Azure',
                        'https://portal.azure.com → switch directory to **'.$client->name.'** → Microsoft Entra ID → Groups',
                        [
                            'Stay in **'.$client->name.'** directory.',
                            'Entra left menu → **Manage** → **Groups** → **All groups**.',
                            'Click **New group**.',
                            'Group type dropdown → **Security**.',
                            'Group name → type exactly **'.$groupName.'**.',
                            'Membership type → **Assigned**.',
                            'Members / Owners → add nobody.',
                            'Click **Create**.',
                            'Back on **All groups**, open **'.$groupName.'**.',
                            'Group left menu → **Overview**.',
                            'Copy **Object ID** (GUID) into Notepad.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Copy Tenant ID from customer Azure',
                        'https://portal.azure.com → customer directory → Microsoft Entra ID → Overview',
                        [
                            'Still in **'.$client->name.'** directory.',
                            'Entra left menu → **Overview** (tenant overview — not the group Overview).',
                            'Copy **Tenant ID** (GUID) into Notepad.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Paste both IDs into this portal',
                        'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left)',
                        [
                            'Return to this portal Edit Client tab.',
                            'Left column → **Entra tenant ID** → paste Tenant ID.',
                            'Left column → **Entra group ID** → paste group Object ID.',
                            'Click orange **Save client** (left).',
                        ],
                    ),
                ],
                verify: [
                    'Both Entra IDs show on the left and this step is Done (auto or after Save checklist).',
                ],
            )),

            $this->withManual([
                'key' => 'entra_admin_consent_granted',
                'title' => 'Accept Portal access in customer tenant',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ], OnboardingManual::build(
                notes: [
                    'This Accept is only for portal sync (Graph). SuperOps login Accept is a later step with a different orange button.',
                    'The customer does nothing. An On IT technician completes this using delegated / GDAP access.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Accept with the orange button',
                        'This checklist step (right) → Microsoft permissions page',
                        [
                            'On this checklist step, click orange **Open Portal Accept for customer tenant** (above). Or use **Copy link** and open it in a private window.',
                            'If you are already in On IT Azure, use private/incognito so Microsoft does not reuse the wrong tenant.',
                            'Sign in with the **On IT technician account that has the required GDAP admin role** for '.$client->name.'.',
                            'Read the top of the Microsoft permissions page — tenant name must be **'.$client->name.'**, not On IT Technology Partners.',
                            'Click **Accept**.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Verify Accept in customer Azure',
                        'https://portal.azure.com → **'.$client->name.'** → Enterprise applications → OnIT Portal for Portals',
                        [
                            'Open https://portal.azure.com → top-right switcher → **'.$client->name.'**.',
                            'Microsoft Entra ID → **Enterprise applications** → **All applications**.',
                            'Search **OnIT Portal for Portals** (or On IT Portal) → open it.',
                            'Left menu → **Permissions** (under Manage or Security) → Application permissions show **Granted for '.$client->name.'**.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Mark complete on this portal',
                        'https://app.onit.ltd → this checklist (right)',
                        [
                            'Tick **Mark this step complete** → click **Save checklist** (right).',
                        ],
                    ),
                ],
                verify: [
                    'Accept succeeded and OnIT Portal for Portals permissions are Granted in the customer tenant.',
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
                            'Tick **Mark this step complete** → **Save checklist** (right).',
                        ],
                    ),
                ],
                verify: [
                    'You have Tenant URL + Secret Token for '.$client->name.' ready to paste.',
                ],
            )),

            $this->withManual([
                'key' => 'superops_scim_app',
                'title' => 'Create SuperOps SCIM app in Entra',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $scimAppComplete,
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $scimTokensComplete,
            ], OnboardingManual::build(
                sections: [
                    OnboardingManual::section(
                        'Create the non-gallery enterprise app',
                        'https://portal.azure.com → **'.$client->name.'** → Enterprise applications',
                        [
                            'Open https://portal.azure.com.',
                            'Top-right directory switcher → **'.$client->name.'**.',
                            'Open **Microsoft Entra ID**.',
                            'Left menu → **Manage** → **Enterprise applications** → **All applications**.',
                            'Click **New application**.',
                            'Click **Create your own application**.',
                            'Name the app exactly **'.$appName.'**.',
                            'Select **Integrate any other application you don’t find in the gallery (Non-gallery)**.',
                            'Click **Create**. Wait until the enterprise app Overview opens.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Paste SuperOps tokens and Test Connection',
                        'Same app → Manage → Provisioning → Admin Credentials',
                        [
                            'Left menu under the app → expand **Manage** if needed → click **Provisioning**.',
                            'If you see a **Get started** card, click **Connect your application**.',
                            'Provisioning Mode → **Automatic**.',
                            'Click the **Admin Credentials** section header to expand it (Tenant URL / Secret Token stay hidden until you expand).',
                            'Authentication method → **Bearer Authentication** (leave Azure’s default).',
                            'Tenant URL box → paste SuperOps **Tenant URL** from the previous step.',
                            'Secret Token box → paste SuperOps **Secret Token / Auth Token**.',
                            'Click **Test Connection**. Wait for success. Do not continue if it fails — regenerate tokens in SuperOps and paste again.',
                            'Click the toolbar **Save** on the Provisioning page.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Mark complete on this portal',
                        'https://app.onit.ltd → this checklist (right)',
                        [
                            'Tick **Mark this step complete** → **Save checklist** (right).',
                        ],
                    ),
                ],
                verify: [
                    'App **'.$appName.'** exists under Enterprise applications, Test Connection succeeded, Provisioning saved.',
                ],
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
                'title' => 'Accept SuperOps login in customer tenant',
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
                    : ['If Dry run / Sync now are missing after save, stop and message Tom.'],
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
                    'Always Dry run first. Sync now applies changes.',
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
                        'https://portal.azure.com → **'.$client->name.'** → Groups + Enterprise applications',
                        array_values(array_filter([
                            'Open https://portal.azure.com → switch directory to **'.$client->name.'**.',
                            'Microsoft Entra ID → **Groups** → **'.$groupName.'** → **Members** — members listed (portal added them).',
                            $usesGroupScim
                                ? null
                                : 'Enterprise applications → **'.$appName.'** → **Users and groups** — users listed after sync (Free path).',
                            $usesGroupScim
                                ? null
                                : 'Enterprise applications → **SuperOps Requester SSO (On IT)** → **Users and groups** — every active licensed user listed after Sync now (Free path; portal assigned them automatically).',
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
                            'If Last synced is set, this step is Done. If Sync now already ran, tick **Mark this step complete** → **Save checklist** (right).',
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
                    'Never validate requester SSO with an On IT staff account.',
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
                            'Tick **Mark this step complete** → **Save checklist** (right).',
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
                            'Tick **Mark this step complete** → **Save checklist** (right).',
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
        $sections = [
            OnboardingManual::section(
                'Edit SCIM attribute mappings',
                'https://portal.azure.com → **'.$clientName.'** → Enterprise applications → '.$appName.' → Provisioning → Attribute mapping',
                [
                    'Open https://portal.azure.com.',
                    'Top-right directory switcher → **'.$clientName.'**.',
                    'Microsoft Entra ID → **Enterprise applications** → **All applications**.',
                    'Open **'.$appName.'**.',
                    'Left menu → **Manage** → **Provisioning**.',
                    'Under Provisioning, open **Attribute mapping** (sometimes labelled **Mappings**).',
                    'Click **Provision Microsoft Entra ID Users**.',
                    'For **name.givenName**: click the row → Mapping type **Direct** → Source attribute **givenName** → Apply this mapping **Always** → OK.',
                    'For **name.familyName**: click the row → Mapping type **Direct** (delete any Expression if present) → Source attribute **extensionAttribute1** → Default if null **[surname]** → Apply **Always** → OK.',
                    'For **name.formatted**: click the row → Mapping type **Direct** → Source attribute **displayName** → Apply **Always** → OK.',
                    'Do **not** paste an Expression into “Default value if null” on a Direct mapping — it will not run.',
                    'Click **Save** at the top of the attribute mapping page.',
                ],
            ),
            OnboardingManual::section(
                'Create App role Value User',
                'https://portal.azure.com → **'.$clientName.'** → App registrations → '.$appName.' → App roles',
                [
                    'Microsoft Entra ID → **App registrations** → **All applications**.',
                    'Search **'.$appName.'** → open it (same app, App registrations blade — not only Enterprise applications).',
                    'Left menu → **App roles**.',
                    'If no enabled role with Value **User** exists: **Create app role**.',
                    'Display name: **User**.',
                    'Allowed member types: **Users/Groups**.',
                    'Value: **User** (must not be blank — blank Value breaks Sync now).',
                    'Description: **Default access for SCIM users**.',
                    'Enable this app role: **Yes**.',
                    'Click **Apply** then **Save**.',
                    'If duplicate app roles with blank Value exist, remove/disable them — keep one enabled role with Value **User**.',
                ],
            ),
        ];

        if ($usesGroupScim) {
            $sections[] = OnboardingManual::section(
                'Assign Portal group to the enterprise app',
                'https://portal.azure.com → Enterprise applications → '.$appName.' → Users and groups',
                [
                    'Return to **Enterprise applications** → **'.$appName.'**.',
                    'Left menu → **Manage** → **Users and groups**.',
                    'Click **Add user/group**.',
                    'Under Users and groups → **None Selected** → open **Groups**.',
                    'Select **'.$groupName.'** → **Select** → **Assign**.',
                ],
            );
        } else {
            $sections[] = OnboardingManual::section(
                'Copy Application (client) ID into this portal (Entra Free)',
                'Azure App registrations Overview → then https://app.onit.ltd Edit Client (left)',
                [
                    'Microsoft Entra ID → **App registrations** → **All applications**.',
                    'Search **'.$appName.'** → open it.',
                    'Stay on **Overview**.',
                    'Copy **Application (client) ID** (GUID under that exact label).',
                    'Do **not** copy **Object ID** on the same page.',
                    'Return to this portal tab: **Admin → Clients → Edit '.$clientName.'**.',
                    'Left column → **SuperOps Application (client) ID** → paste.',
                    'Click orange **Save client** (left).',
                ],
            );
        }

        $sections[] = OnboardingManual::section(
            'Start provisioning in Azure',
            'https://portal.azure.com → Enterprise applications → '.$appName.' → Provisioning',
            [
                'Back in Azure: **Enterprise applications** → **'.$appName.'** → **Manage** → **Provisioning**.',
                'Click toolbar **Start provisioning**.',
                'Confirm the status shows provisioning is on / started.',
                'Tick **Mark this step complete** → **Save checklist** (right).',
            ],
        );

        return OnboardingManual::build(
            notes: [
                $usesGroupScim
                    ? 'P1: assign Portal group once here. Sync now later keeps membership updated.'
                    : 'Free: you cannot assign the Portal group to this enterprise app. Application (client) ID on the portal is required.',
            ],
            sections: $sections,
            verify: [
                $usesGroupScim
                    ? 'Mappings saved, App role User exists, group '.$groupName.' assigned, provisioning started.'
                    : 'Mappings saved, App role User exists, SuperOps Application (client) ID saved on portal (not Object ID), provisioning started.',
            ],
        );
    }

    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;

        $assignSection = $usesGroupScim
            ? OnboardingManual::section(
                'Assign Portal group in customer Azure',
                'https://portal.azure.com → **'.$clientName.'** → Enterprise applications → SuperOps Requester SSO (On IT) → Users and groups',
                [
                    'Open https://portal.azure.com → switch directory to **'.$clientName.'**.',
                    'Microsoft Entra ID → **Enterprise applications** → **All applications**.',
                    'Search **SuperOps Requester SSO (On IT)** → open it (created by Accept).',
                    'Left menu → **Properties**: **Enabled for users to sign-in?** = Yes. **Assignment required?** = Yes is expected.',
                    'Left menu → **Manage** → **Users and groups** → **Add user/group**.',
                    'Groups → select **'.$groupName.'** → **Select** → **Assign**.',
                ],
            )
            : OnboardingManual::section(
                'Confirm the SSO app exists — do not add users manually (Free)',
                'https://portal.azure.com → **'.$clientName.'** → Enterprise applications → SuperOps Requester SSO (On IT) → Users and groups',
                [
                    'Open https://portal.azure.com → switch directory to **'.$clientName.'**.',
                    'Microsoft Entra ID → **Enterprise applications** → **All applications**.',
                    'Search **SuperOps Requester SSO (On IT)** → open it (created by Accept).',
                    'Left menu → **Properties**: **Enabled for users to sign-in?** = Yes. **Assignment required?** = Yes is expected.',
                    'Do **not** add users one-by-one. Entra ID Free cannot assign the Portal group, so portal **Sync now** assigns every active licensed customer user directly.',
                    'After marking this acceptance complete, finish steps 09 and 10. Step 10 verifies **Users and groups** was filled automatically.',
                ],
            );

        return OnboardingManual::build(
            notes: [
                'Do not open SuperOps **Client SSO**. Do not edit certificates / Global SSO for this client.',
                'The customer does nothing. An On IT technician completes the Accept and all Azure work using delegated / GDAP access.',
                'If Microsoft then opens usauth.superops.ai with JSON `{"code":"unknown"}`, that is a bad redirect — not Accept failure. Check customer Enterprise applications for **SuperOps Requester SSO (On IT)**.',
            ],
            sections: [
                OnboardingManual::section(
                    'Accept with the orange button',
                    'This checklist step (right) → Microsoft permissions page',
                    [
                        'On this checklist step, click orange **Open SuperOps SSO Accept for customer tenant** (above).',
                        'Open it in private/incognito if Microsoft has cached the wrong directory.',
                        'Sign in with the **On IT technician account that has the required GDAP admin role** for '.$clientName.'.',
                        'Confirm Microsoft shows tenant **'.$clientName.'**, not On IT Technology Partners LTD.',
                        'Click **Accept**.',
                    ],
                ),
                $assignSection,
                OnboardingManual::section(
                    'Mark complete on this portal',
                    'https://app.onit.ltd → this checklist (right)',
                    [
                        'Tick **Mark this step complete** → **Save checklist** (right).',
                    ],
                ),
            ],
            verify: [
                $usesGroupScim
                    ? 'Customer Azure has SuperOps Requester SSO (On IT) with the Portal group assigned.'
                    : 'Customer Azure has SuperOps Requester SSO (On IT). Users are assigned automatically by portal Sync now — not manually here.',
            ],
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
