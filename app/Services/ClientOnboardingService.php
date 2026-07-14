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

    /** @var list<string> */
    public const MANUAL_CHECKPOINTS = [
        'entra_group_created',
        'superops_scim_configured',
        'entra_admin_consent_granted',
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
     * Customer Global Admin Accept URL for SuperOps Requester SSO (On IT) — Global SSO app (not Client SSO).
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
        $entraTier = $this->normalizeEntraLicenseTier($client->entra_license_tier);
        $usesGroupScim = $this->usesEntraGroupScim($entraTier);

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

        $portalSyncRunComplete = $syncRun || (bool) ($checklist['portal_sync_run'] ?? false);

        // Checklist is only shown on Edit (after Create). No "create client" step — you cannot reach this UI without one.
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
                        'Copy SuperOps Account ID',
                        config('services.superops.portal_url', 'https://app.superops.ai'),
                        [
                            'Sign in to the SuperOps MSP console.',
                            'Go to Clients → open this customer.',
                            'Copy the Account ID from the browser URL (long number after /client/).',
                        ],
                    ),
                    OnboardingManual::section(
                        'Save on the portal',
                        'https://app.onit.ltd — this page, left column',
                        [
                            'Paste the Account ID into SuperOps Account ID.',
                            'Click Save client.',
                        ],
                    ),
                ],
                verify: [
                    'SuperOps → Clients → this customer → Requesters — people exist with correct @customer work emails (SCIM matches by email later).',
                ],
            )),
            $this->withManual([
                'key' => 'pax8_linked',
                'title' => 'Link Pax8 company (optional)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $pax8Configured,
                'manual' => false,
                'auto_detected' => $pax8Configured,
                'blocked' => false,
            ], OnboardingManual::build(
                prerequisites: [
                    'Skip this entire step if the client does not use the Pax8 licensing tile on the dashboard.',
                ],
                sections: [
                    OnboardingManual::section(
                        'Link Pax8 company',
                        'https://app.pax8.com — then this page',
                        [
                            'Pax8 → Companies → open the customer → copy the company UUID from the URL or profile.',
                            'On the left: paste Pax8 Company ID and tick Pax8 access if they use the tile.',
                            'Click Save client.',
                        ],
                    ),
                ],
            )),
            $this->withManual([
                'key' => 'entra_group_created',
                'title' => 'M365 security group + Entra tenant',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraTenantSaved && $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ], $this->entraGroupGuide($groupName, $client->name, $usesGroupScim)),
            $this->withManual([
                'key' => 'entra_admin_consent_granted',
                'title' => 'Portal Graph admin consent',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ], $this->adminConsentGuide($client->name)),
            $this->withManual([
                'key' => 'superops_scim_configured',
                'title' => 'SuperOps SCIM (requesters)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => (bool) ($checklist['superops_scim_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], $this->superOpsScimGuide($client->name, $groupName, $usesGroupScim)),
            $this->withManual([
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps requester SSO (Global SSO Accept)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], $this->superOpsClientSsoGuide($client, $groupName, $usesGroupScim)),
            $this->withManual([
                'key' => 'portal_sync_configured',
                'title' => 'Enable portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $syncConfigured,
                'manual' => false,
                'auto_detected' => $syncConfigured,
                'blocked' => ! $entraTenantSaved,
            ], $this->portalSyncConfiguredGuide($syncEnabledGlobally, $usesGroupScim)),
            $this->withManual([
                'key' => 'portal_sync_run',
                'title' => 'Run portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $portalSyncRunComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ], $this->portalSyncRunGuide($client, $usesGroupScim)),
            $this->withManual([
                'key' => 'login_tested',
                'title' => 'Test sign-in',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => (bool) ($checklist['login_tested'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $portalSyncRunComplete,
            ], $this->loginTestGuide()),
            $this->withManual([
                'key' => 'handed_off',
                'title' => 'Hand off to customer',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => (bool) ($checklist['handed_off'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! ($checklist['login_tested'] ?? false),
            ], $this->handoffGuide()),
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

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
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

    private function entraEnterpriseAppPath(string $appName): string
    {
        return 'Enterprise applications → All applications → '.$appName;
    }

    /**
     * Current Entra enterprise-app UI hides Provisioning, Attribute mapping, Users and groups, and Single sign-on under Manage.
     */
    private function entraManagePath(string $item): string
    {
        return 'Left menu → Manage → '.$item.' (expand Manage first if the submenu is collapsed)';
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function entraGroupGuide(string $groupName, string $clientName, bool $usesGroupScim): array
    {
        $notes = $usesGroupScim
            ? [
                '**On Entra ID P1:** this group decides who SuperOps syncs as requesters. In step 05 you assign **'.$groupName.'** to the SuperOps app once in Azure. After that, every **Sync now** keeps membership up to date — do not add or remove people by hand.',
                'When you create the group, leave **Members** empty. **Sync now** (step 08) adds licensed M365 users and shared mailboxes for you.',
                'If requesters already exist in SuperOps, leave them. SCIM matches by email — you will not get duplicates.',
            ]
            : [
                '**Why this group on Entra ID Free?** SuperOps does not use this group for provisioning on Free — that is normal. You still create it because **Sync now** fills it with everyone the portal manages, so you always have a clear list in M365 admin. If the customer upgrades to P1 later, this group is already ready — assign it to the SuperOps app once in step 05 instead of starting over.',
                'When you create the group, leave **Members** empty. Do not add people in Azure. In step 08, click **Sync now** — the portal adds licensed users and shared mailboxes to **'.$groupName.'** for you.',
                '**Do not assign this group to the SuperOps app in Azure.** On Free, Azure blocks that. SuperOps requesters come from step 05 (**SuperOps Application (client) ID**). After sync you will also see users on the SuperOps app — that list is what SuperOps uses on Free.',
                'When someone leaves, **Sync now** removes them from this group and from the SuperOps app. You do not need to clean up manually.',
            ];

        return OnboardingManual::build(
            prerequisites: [
                'On IT tenant platform setup is already complete (OnIT Portal for Portals has all 9 Graph permissions). That is not part of this client checklist.',
                'You are signed into portal.azure.com as the **'.$clientName.'** tenant, not On IT — switch directory top-right if needed.',
                $usesGroupScim
                    ? 'Customer has **Entra ID P1** (or higher) — set **Entra license tier** on the left to match.'
                    : 'Customer has **Entra ID Free** — set **Entra license tier** on the left to match.',
            ],
            sections: [
                OnboardingManual::section(
                    'Create the security group',
                    'https://portal.azure.com — customer tenant',
                    [
                        'Microsoft Entra ID → Manage → Groups → New group.',
                        'Group type: Security. Membership type: Assigned.',
                        'Group name: '.$groupName.'.',
                        'Members: leave empty — in step 08 you will click Sync now and the portal fills this group for you.',
                        'After Create: open the group → Overview → copy Object ID.',
                    ],
                ),
                OnboardingManual::section(
                    'Save tenant and group on the portal',
                    'https://app.onit.ltd — this page, left column',
                    [
                        'Microsoft Entra ID → Overview → Tenant ID → paste into **Entra tenant ID** on the left.',
                        'Group → Overview → Object ID → paste into **Entra group ID** on the left.',
                        'Click **Save client**. This step is Done when both IDs are saved.',
                    ],
                ),
            ],
            verify: array_values(array_filter([
                'Customer Entra → Groups → '.$groupName.' → Members is empty until you run Sync now in step 08.',
                $usesGroupScim
                    ? null
                    : 'After the first Sync now: the same group shows members in M365 admin — you did not add them yourself. SuperOps requesters still come from step 05 (SuperOps app), not from assigning this group.',
            ])),
            notes: $notes,
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function adminConsentGuide(string $clientName): array
    {
        return OnboardingManual::build(
            prerequisites: [
                'Entra tenant ID saved on the left (generates the Admin consent URL below).',
                'You have Global Administrator rights in the customer tenant (or GDAP with consent rights).',
            ],
            notes: [
                'This step is Microsoft only — not Sign in with Microsoft on app.onit.ltd.',
                'The consent page must show **'.$clientName.'** (this customer), not On IT Technology Partners.',
                'After Accept, Microsoft redirects briefly to the portal success page — that is expected, not a login failure. Step 04 ticks automatically.',
                'This grants OnIT Portal for Portals these Application permissions: User.Read.All, User.ReadWrite.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, GroupMember.ReadWrite.All, AppRoleAssignment.ReadWrite.All, Application.Read.All, Synchronization.ReadWrite.All.',
                'Application.Read.All resolves SuperOps Application (client) ID to the enterprise app during sync — required when using client ID on Entra ID Free.',
                'User.ReadWrite.All writes User Mailbox / Shared Mailbox to extensionAttribute1 — Entra SCIM appends to SuperOps Last name.',
                'Synchronization.ReadWrite.All triggers SCIM provision-on-demand when you click Sync now.',
                'Re-consent even if you consented before — new permissions (especially GroupMember.ReadWrite.All) are not included in old consent.',
            ],
            sections: [
                OnboardingManual::section(
                    'Grant admin consent',
                    'Use the Admin consent URL below (login.microsoftonline.com/adminconsent)',
                    [
                        'Copy or open the Admin consent URL below — it must contain /adminconsent.',
                        'Use a private/incognito window so you are not signed into the On IT tenant.',
                        'Sign in as a Global Administrator of **'.$clientName.'**.',
                        'Confirm the page shows **'.$clientName.'**, not On IT.',
                        'Review the permissions list → click Accept.',
                    ],
                ),
            ],
            verify: [
                'Customer Entra → Enterprise applications → OnIT Portal for Portals → Manage → Permissions → all Application permissions show Granted.',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function portalSyncConfiguredGuide(bool $syncEnabledGlobally, bool $usesGroupScim): array
    {
        $prerequisites = [
            'Steps 03–06 done (step 06 = customer GA Accepted SuperOps Requester SSO).',
            'On the left you already have Entra tenant ID and Entra group ID saved from step 03.',
        ];

        if (! $usesGroupScim) {
            $prerequisites[] = 'SuperOps Application (client) ID is saved on the left (Entra ID Free — from step 05).';
        }

        $notes = [
            'This step is only on **this page**. Tick **Entra sync enabled** and click **Save client**.',
        ];

        if (! $syncEnabledGlobally) {
            $notes[] = 'If the **Dry run sync** / **Sync now** buttons are missing after you save: stop and message Tom.';
        }

        return OnboardingManual::build(
            prerequisites: $prerequisites,
            notes: $notes,
            sections: [
                OnboardingManual::section(
                    'Part A — Turn sync on for this client',
                    'https://app.onit.ltd — this Edit Client page, left column, Microsoft Entra sync',
                    array_values(array_filter([
                        'Scroll to **Microsoft Entra sync** on the left.',
                        'Check that **Entra tenant ID** and **Entra group ID** are filled (from step 03).',
                        $usesGroupScim
                            ? null
                            : 'Check that **SuperOps Application (client) ID** is filled (from step 05 — Entra ID Free).',
                        'Tick **Entra sync enabled**.',
                        'Click the orange **Save client** button.',
                    ])),
                ),
            ],
            verify: [
                'After Save client, the page reloads with **Entra sync enabled** still ticked.',
                'Buttons **Dry run sync** and **Sync now** appear under the form (you use them in step 08).',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function portalSyncRunGuide(Client $client, bool $usesGroupScim): array
    {
        $prerequisites = [
            'Step 07 done — **Entra sync enabled** is ticked and saved on the left.',
            'You are still on this Edit Client page (app.onit.ltd).',
        ];

        $dryRunSteps = [
            'Scroll down under the form to the orange buttons **Dry run sync** and **Sync now**.',
            'Click **Dry run sync** first (safe — does not change users yet).',
            'Wait for the green or red message at the **top** of the page.',
            'Green message should show Created / Updated / Deactivated counts for portal users.',
        ];

        if ($usesGroupScim) {
            $dryRunSteps[] = 'You should also see a SuperOps group line (members added). If that line is missing, go back to step 03 and save **Entra group ID**.';
        } else {
            $dryRunSteps[] = 'You should see SuperOps group members **and** SuperOps app users. If either is missing, check **Entra group ID** and **SuperOps Application (client) ID** on the left, Save client, then dry run again.';
        }

        $dryRunSteps = array_merge($dryRunSteps, [
            'If the message is red: read it. Common fixes — redo admin consent (step 04), or check the SuperOps app has an App role named User (step 05). If you are stuck, send Tom the error text.',
            'When the dry run looks good, click **Sync now**.',
            'Sync now runs in the background — you can leave the page. After 1–2 minutes, refresh this Edit page and check **Last synced** under Microsoft Entra sync.',
        ]);

        return OnboardingManual::build(
            prerequisites: $prerequisites,
            notes: [
                '**Dry run sync** = preview. **Sync now** = apply. Always dry run first. Both buttons are on this Edit Client page under the form.',
                'After Sync now: if **Last synced** is not showing yet, tick **Mark this step complete** and click **Save checklist**.',
            ],
            sections: [
                OnboardingManual::section(
                    'Part A — Dry run, then Sync now',
                    'https://app.onit.ltd — this Edit Client page, left column (buttons under the form)',
                    $dryRunSteps,
                ),
            ],
            verify: array_values(array_filter([
                'Admin → Users — filter by this client — licensed users appear (plain names).',
                'Customer Entra → Groups → On IT Portal - '.$client->name.' → Members — people are listed (portal added them; you did not add them by hand).',
                $usesGroupScim
                    ? null
                    : 'Customer Entra → Enterprise applications → SuperOps - '.$client->name.' → Manage → Users and groups — users listed (portal assigned them on Free).',
                'SuperOps → Clients → '.$client->name.' → Requesters — emails match (may take a few minutes after SCIM).',
                'This step completes when **Last synced** appears after Sync now — or tick **Mark this step complete** → **Save checklist** once you have clicked Sync now.',
            ])),
        );
    }

    /**
     * SCIM provisions SuperOps requesters from M365. Requester login uses On IT Global SSO (step 06).
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsScimGuide(string $clientName, string $groupName, bool $usesGroupScim): array
    {
        $appName = 'SuperOps - '.$clientName;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        $notes = [
            'One Entra app only for SCIM: '.$appName.' — provisioning only on this customer app. Requester **login** uses On IT **Global SSO** + customer **Accept** (step 06) — not SAML on this app.',
            'Under Manage → Provisioning, expand Admin Credentials before Tenant URL and Secret Token appear.',
            'Authentication method must be Bearer Authentication (Azure default).',
            'Tick Mark this step complete on this page when done.',
        ];

        if ($usesGroupScim) {
            $notes[] = 'Assign security group **'.$groupName.'** to this app once — portal sync keeps the group filled.';
        }

        $partDSteps = $usesGroupScim
            ? [
                $this->entraManagePath('Users and groups').' → Add user/group → Groups → '.$groupName.' → Assign (once only).',
                $this->entraManagePath('Provisioning').' → toolbar **Start provisioning**.',
            ]
            : [
                'Copy **Application (client) ID**: Microsoft Entra ID → App registrations → All applications → '.$appName.' → Overview. (Same value as Enterprise app → Overview → Application ID.) Do not copy Object ID.',
                'Paste on **app.onit.ltd**: Admin → Clients → Edit '.$clientName.' → left column **SuperOps Application (client) ID** → **Save client**.',
                $this->entraEnterpriseAppPath($appName).' → '.$this->entraManagePath('Provisioning').' → toolbar **Start provisioning**.',
            ];

        $partDTitle = $usesGroupScim
            ? 'Part D — Assign group and start provisioning'
            : 'Part D — Save app ID on portal and start provisioning';

        $partDWhere = $usesGroupScim
            ? 'https://portal.azure.com — '.$appName
            : 'App registrations (copy ID) — then this Edit client page (paste) — then Azure Provisioning (start)';

        $partDNotes = $usesGroupScim
            ? [
                'After portal **Sync now** (step 08), portal sync keeps group membership updated — you only assign the group once here.',
            ]
            : [
                'On Entra ID Free you do not assign **'.$groupName.'** to the SuperOps app in Azure — step 03 already created the group for the portal to maintain. Paste the **SuperOps Application (client) ID** on this page (left column), then start provisioning. After **Sync now** (step 08), users appear on the SuperOps app — that is the list SuperOps uses on Free.',
            ];

        $verify = $usesGroupScim
            ? [
                $this->entraEnterpriseAppPath($appName).' → '.$this->entraManagePath('Provisioning').' → Monitor → Provisioning logs — users appear after a few minutes.',
                'SuperOps → Clients → '.$clientName.' → Requesters — Last name shows (User Mailbox) or (Shared Mailbox).',
            ]
            : [
                $this->entraEnterpriseAppPath($appName).' → '.$this->entraManagePath('Users and groups').' — licensed users after portal Sync now.',
                $this->entraEnterpriseAppPath($appName).' → '.$this->entraManagePath('Provisioning').' → Monitor → Provisioning logs — users appear after a few minutes.',
                'SuperOps → Clients → '.$clientName.' → Requesters — Last name shows (User Mailbox) or (Shared Mailbox).',
            ];

        return OnboardingManual::build(
            prerequisites: array_values(array_filter([
                'M365 security group **'.$groupName.'** created in step 03 (empty is fine).',
                'Portal Graph admin consent accepted before Run portal sync.',
            ])),
            notes: $notes,
            sections: [
                OnboardingManual::section(
                    'Part A — Get SCIM credentials from SuperOps',
                    $superOpsUrl.' — SuperOps MSP console',
                    [
                        'Integrations → Microsoft Entra ID → Generate Tokens.',
                        'Select '.$clientName.'.',
                        'Copy Tenant URL and Secret Token (Auth Token).',
                        'Store in password manager — regenerate if exposed.',
                    ],
                ),
                OnboardingManual::section(
                    'Part B — Create and configure the Entra app',
                    'https://portal.azure.com — customer tenant',
                    [
                        'Enterprise applications → New application → Create your own application → non-gallery.',
                        'Name: '.$appName.' → Create.',
                        $this->entraEnterpriseAppPath($appName).' → open the app.',
                        $this->entraManagePath('Provisioning').'.',
                        'If the page shows Get started: click Connect your application (opens the same Admin Credentials form).',
                        'Provisioning Mode dropdown → Automatic.',
                        'Expand Admin Credentials (click the section heading — fields are hidden until expanded).',
                        'Authentication method → Bearer Authentication (leave selected — do not change).',
                        'Tenant URL → paste from SuperOps (not the customer Azure tenant URL).',
                        'Secret Token → paste Auth Token from SuperOps (same value — different label).',
                        'Click Test Connection — must succeed → Save (toolbar).',
                        'Microsoft Entra ID → App registrations → All applications → '.$appName.' → App roles → Create app role if none exists: Display name User, Allowed member types Users/Groups, Value User, Description Default access for SCIM users, Enable → Save (required for portal sync on Entra ID Free).',
                    ],
                ),
                OnboardingManual::section(
                    'Part C — SCIM name mapping (SuperOps requester names)',
                    'https://portal.azure.com — '.$appName,
                    [
                        $this->entraManagePath('Attribute mapping').'.',
                        'Open Provision Microsoft Entra ID Users.',
                        'name.givenName → Direct → givenName → Always.',
                        'name.familyName → Direct → extensionAttribute1 → Default if null [surname] → Always.',
                        'name.formatted → Direct → displayName → Always → Save.',
                    ],
                    notes: [
                        '**Why extensionAttribute1:** Portal sync writes each SuperOps **Last name** with a suffix — e.g. **Surname (User Mailbox)** or **Accounts (Shared Mailbox)** — not the plain M365 display name. Map **name.familyName** **Direct** from extensionAttribute1 so SCIM exports that value. Do **not** add an Expression in Azure.',
                        '**After Save:** Run portal **Sync now** (checklist step 08). The portal fills extensionAttribute1 for each licensed user and shared mailbox, then triggers SCIM provision-on-demand.',
                    ],
                ),
                OnboardingManual::section(
                    $partDTitle,
                    $partDWhere,
                    $partDSteps,
                    notes: $partDNotes,
                ),
            ],
            verify: array_merge([
                $this->entraEnterpriseAppPath($appName).' → '.$this->entraManagePath('Attribute mapping').' → name.familyName Direct from extensionAttribute1.',
            ], $verify),
        );
    }

    /**
     * SuperOps requester Global SSO (SAML) — multitenant app; per client the customer GA Accepts it.
     * Platform SAML (Entity ID / cert / IDP URL) is one-time. Per client: Accept + assign group.
     *
     * @see https://support.superops.com/en/articles/11583025-setting-up-requester-sso-in-superops
     * @see Brain/SuperOpsRequesterSsoSetup.md
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');
        $entityId = 'https://clientuser.superops.ai';
        $appClientId = config('services.superops.requester_sso_client_id', 'bf1c303e-6015-43f7-abb2-5dfe8f67a5a1');
        $assignStep = $usesGroupScim
            ? 'Users and groups → Add user/group → assign security group **'.$groupName.'** (Assignment required = Yes on this app).'
            : 'Users and groups → Add user/group → assign **'.$groupName.'** and/or the requesters who must Microsoft-sign-in (Assignment required = Yes). On Free, portal Sync now also maintains SuperOps app assignments for SCIM — still assign the Portal group here for SSO access.';

        return OnboardingManual::build(
            prerequisites: [
                'Step 03 done — Entra tenant ID saved (needed for the Accept URL below).',
                'Step 04 is a **different** Accept — Portal Graph (`OnIT Portal for Portals`). This step Accepts **SuperOps Requester SSO (On IT)** only.',
                'Customer Global Admin can sign in at Microsoft (GDAP or send them the Open link).',
            ],
            notes: [
                '**Per client — customer Global Admin must Accept this app.** SuperOps **Global SSO** (cert / Login URL / Entity ID) is configured **once** in On IT + SuperOps. Every new client still needs their tenant to **Accept** the multitenant Entra app **SuperOps Requester SSO (On IT)** (Application ID `'.$appClientId.'`).',
                '**Do not** open SuperOps **Client SSO**. Do **not** create a Client SSO configuration for '.$clientName.'. Do **not** put SAML on the customer SCIM app **SuperOps - '.$clientName.'**.',
                'If Microsoft says the user is not in tenant **On IT Technology Partners LTD**, this Accept (Part A) was skipped or failed for that customer tenant.',
            ],
            sections: [
                OnboardingManual::section(
                    'Part A — Customer Global Admin Accepts SuperOps Requester SSO',
                    'This Edit Client page — checklist step 06 → SuperOps SSO Accept URL',
                    [
                        'Use the **SuperOps SSO Accept URL** shown on this step (Open or Copy). It is **not** the step 04 Portal Graph consent URL.',
                        'Sign in at Microsoft as a **'.$clientName.'** Global Admin (not an @onit.ltd account).',
                        'Review permissions → **Accept**. This creates the enterprise app in the **customer** tenant.',
                        'If you land on app.onit.ltd login, you opened the wrong link — come back here and use Open on this step.',
                    ],
                ),
                OnboardingManual::section(
                    'Part B — Assign who can use SSO (customer tenant)',
                    'Customer Entra — Enterprise applications → SuperOps Requester SSO (On IT)',
                    [
                        'In the **'.$clientName.'** Entra tenant (not On IT home), open Enterprise applications → find **SuperOps Requester SSO (On IT)** (appears after Part A Accept).',
                        'Confirm Properties: **Enabled for users to sign-in?** = Yes. **Assignment required?** = Yes (expected).',
                        $assignStep,
                        'Do **not** change Reply URL, Entity ID, certificate, or SuperOps Global SSO settings for this client.',
                    ],
                    notes: [
                        'Verified On IT home app (reference): Application ID `'.$appClientId.'`, Object ID `cc9c46a8-b038-4591-beab-414584233245`, Reply URL under portal.onit.ltd `/accounts-web/accounts/saml/response/…`.',
                    ],
                ),
                OnboardingManual::section(
                    'Part C — Only if Global SSO is broken for EVERY client (rare)',
                    $superOpsUrl.' — Settings → Requester Login → SSO Protected → Global SSO',
                    [
                        'Skip unless Microsoft login fails for **all** clients (platform Global SSO empty or wrong).',
                        'Open **Global SSO** (not Client SSO). Rebuild only on the On IT home enterprise app — see Brain/SuperOpsRequesterSsoSetup.md.',
                        'Entity ID must stay **'.$entityId.'**. Paste Consumer Service URL as Reply URL; paste Login URL + cert body into SuperOps Global SSO → Save.',
                    ],
                ),
            ],
            verify: [
                'Incognito → https://portal.onit.ltd/#/requester/login → Microsoft → customer work email (e.g. admin@'.$clientName.' domain) succeeds — no “not in On IT tenant” error.',
                'Tick **Mark this step complete** → **Save checklist**.',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function loginTestGuide(): array
    {
        return OnboardingManual::build(
            prerequisites: [
                'Run portal sync completed — at least one customer work email exists in Admin → Users.',
                'Use a private/incognito browser — not your On IT technician session.',
            ],
            notes: [
                'Do not use tom.ashby@onit.ltd or other technician accounts — use a customer work email synced in Run portal sync.',
            ],
            sections: [
                OnboardingManual::section(
                    'Test portal sign-in',
                    'https://app.onit.ltd/login',
                    [
                        'Sign in with Microsoft using a customer work email.',
                        'Dashboard loads with SuperOps and Pax8 tiles (if enabled).',
                    ],
                ),
                OnboardingManual::section(
                    'Test SuperOps SSO',
                    'https://app.onit.ltd — after portal login',
                    [
                        'Click the SuperOps tile.',
                        'Should open requester view (not technician role chooser).',
                        'Microsoft sign-in with the same customer email if prompted.',
                    ],
                ),
            ],
            verify: [
                'All tests pass → tick Mark this step complete → Save checklist.',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function handoffGuide(): array
    {
        return OnboardingManual::simple(
            'Email or ticket to the customer contact',
            [
                'Tell them: go to https://app.onit.ltd and sign in with Microsoft using their work email.',
                'They must already be a licensed M365 user (shared mailboxes cannot sign in).',
                'Tick Mark this step complete → Save checklist.',
            ],
            prerequisites: [
                'Test sign-in step complete.',
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

        $client->update(['onboarding_checklist' => $current]);
    }

    /**
     * Persist checklist ticks that match saved client fields (e.g. group ID pasted and Save client clicked).
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
                'Where: SuperOps MSP console, then this field on app.onit.ltd.',
                'SuperOps → Clients → open the customer.',
                'Copy the Account ID from the URL (long number after /client/).',
                'Paste here → Save client. Must match the SuperOps client exactly.',
            ],
            'pax8_company_id' => [
                'Where: Pax8 partner portal (app.pax8.com), then this field.',
                'Companies → open customer → copy company UUID from URL or profile.',
                'Enable Pax8 access if the client should see the licensing tile.',
            ],
            'entra_license_tier' => [
                'Set this before you work through step 03 — the checklist changes depending on what the customer has.',
                'Entra ID Free — still create the security group in step 03. SuperOps requesters are set up via the SuperOps app in step 05.',
                'Entra ID P1 or higher — step 05 assigns the security group '.$groupName.' to the SuperOps app in Azure.',
                'If you change this after setup, re-read steps 03, 05, and 07.',
            ],
            'entra_tenant_id' => [
                'Where: portal.azure.com (customer tenant) → paste here.',
                'Switch to the customer directory (top-right), not On IT.',
                'Microsoft Entra ID → Overview → Tenant ID (GUID).',
                'Required for all customers — saves the admin consent URL for step 04.',
            ],
            'entra_group_id' => [
                'Create this group in step 03 — Microsoft Entra ID → Groups → New group → name: '.$groupName.'.',
                'Leave Members empty. Copy Object ID from the group Overview and paste here.',
                'Sync now (step 08) fills the group with licensed users and shared mailboxes — do not add members by hand in Azure.',
                $usesGroupScim
                    ? 'On Entra ID P1: in step 05 you assign this group to the SuperOps app once in Azure.'
                    : 'On Entra ID Free: SuperOps uses the app user list from step 05, not this group — but Sync now still fills this group so you can see who the portal manages, and the group is ready if they upgrade to P1 later.',
            ],
            'entra_superops_app_id' => $usesGroupScim
                ? [
                    'Optional on Entra ID P1 when the security group is assigned to the SuperOps enterprise app.',
                    'App registrations → SuperOps - '.$clientName.' → Overview → Application (client) ID (not Object ID).',
                    'Requires Application.Read.All on OnIT Portal for Portals + admin consent in the customer tenant if you use this field.',
                ]
                : [
                    'Required on Entra ID Free — customer Entra → App registrations → SuperOps - '.$clientName.' → Overview → Application (client) ID.',
                    'Do not paste Object ID from the same Overview page — that causes Graph errors.',
                    'Portal sync assigns licensed users to the SuperOps app automatically on Sync now.',
                    'Requires Application.Read.All on OnIT Portal for Portals + admin consent in the customer tenant.',
                ],
            'entra_sync_enabled' => [
                'Where: this page. Turn on after Entra tenant ID is saved.',
                'Requires admin consent (step 04) before Sync now will succeed.',
                $usesGroupScim
                    ? 'On P1: requires Entra group ID. Syncs licensed users + shared mailboxes; maintains the SuperOps group; triggers SCIM provision-on-demand on Sync now.'
                    : 'On Free: requires Entra group ID + SuperOps Application (client) ID. Sync fills the security group and assigns SuperOps app users; triggers SCIM provision-on-demand on Sync now.',
                'Use Dry run sync, then Sync now, on the left.',
            ],
        ];
    }
}
