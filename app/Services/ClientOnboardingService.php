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
                'title' => 'SuperOps Client SSO (SAML)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $superopsLinked,
            ], $this->superOpsClientSsoGuide($client->name, $groupName, $usesGroupScim)),
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
                'complete' => $syncRun,
                'manual' => false,
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
                'blocked' => ! $syncRun,
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
            'Portal Graph admin consent accepted in the customer tenant.',
            'Step 03 saved — Entra tenant ID and Entra group ID on the left.',
        ];

        if (! $usesGroupScim) {
            $prerequisites[] = 'SuperOps SCIM app created and **SuperOps Application (client) ID** pasted on the left (Entra ID Free — step 05).';
        }

        $sections = [
            OnboardingManual::section(
                'Enable sync on the portal',
                'https://app.onit.ltd — this page, left column, Microsoft Entra sync',
                array_values(array_filter([
                    'Entra tenant ID: customer tenant GUID (step 03).',
                    'Entra group ID: Object ID from step 03 — portal maintains group members on Sync now.',
                    $usesGroupScim
                        ? null
                        : 'SuperOps Application (client) ID: from step 05 (required on Entra ID Free).',
                    'Tick Entra sync enabled.',
                    'Click Save client.',
                ])),
            ),
        ];

        if (! $syncEnabledGlobally) {
            $sections[] = OnboardingManual::section(
                'Server configuration',
                'Production server SSH (app.onit.ltd host)',
                [
                    'In production .env set ENTRA_SYNC_ENABLED=true and ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true.',
                    'Run php artisan config:clear on the server (see Run portal sync step for full deploy commands).',
                ],
            );
        }

        return OnboardingManual::build(
            prerequisites: $prerequisites,
            sections: $sections,
        );
    }

    /**
     * @return list<array{title: string, where: string|null, steps: list<string>}>
     */
    private function serverDeploySections(): array
    {
        return [
            OnboardingManual::section(
                'Deploy latest code (if needed)',
                'SSH to app.onit.ltd production host',
                [
                    'cd /var/www/vhosts/onit.ltd/app.onit.ltd',
                    'export PATH="/opt/plesk/php/8.3/bin:$PATH"',
                    'export COMPOSER_ALLOW_SUPERUSER=1',
                    'git pull origin main — or Plesk → Git → Deploy if the live site has no .git folder.',
                    'rm -f public/hot',
                    'composer install --no-dev --optimize-autoloader',
                    'php artisan migrate --force',
                    'php artisan config:clear && php artisan view:clear && php artisan optimize',
                    'Production .env must include: ENTRA_SYNC_ENABLED=true, ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true, MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET for OnIT Portal for Portals.',
                ],
            ),
        ];
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function portalSyncRunGuide(Client $client, bool $usesGroupScim): array
    {
        $clientId = $client->exists ? (string) $client->id : '{client-id}';

        $prerequisites = [
            'Portal Graph admin consent accepted.',
            'Entra sync enabled on the left (Enable portal sync step).',
        ];

        if ($usesGroupScim) {
            $prerequisites[] = 'M365 security group step saved (Entra group ID on the left).';
        } else {
            $prerequisites[] = 'M365 security group + tenant ID saved (step 03).';
            $prerequisites[] = 'SuperOps Application (client) ID saved on the left.';
        }

        $dryRunSteps = [
            'Click Dry run sync first. Read the green or red message at the top of the page.',
            'Expect: Created / Updated / Deactivated counts for portal users.',
            'Expect: SuperOps name labels updated N (User Mailbox / Shared Mailbox in extensionAttribute1).',
        ];

        if ($usesGroupScim) {
            $dryRunSteps[] = 'Expect: SuperOps group: +N / -0 members when Entra group ID is set.';
            $dryRunSteps[] = 'If there is no SuperOps group line: Entra group ID is empty — go back to step 03.';
        } else {
            $dryRunSteps[] = 'Expect: SuperOps group: +N / -0 members — portal fills **On IT Portal - '.$client->name.'** (step 03 group ID).';
            $dryRunSteps[] = 'Expect: SuperOps app: +N / -0 users — portal assigns the SuperOps app via Application (client) ID (step 05).';
            $dryRunSteps[] = 'If either line is missing: check Entra group ID and SuperOps Application (client) ID on the left.';
        }

        $dryRunSteps = array_merge($dryRunSteps, [
            'If errors mention 403 or group: admin consent missing or GroupMember.ReadWrite.All not granted — re-consent in customer tenant.',
            'If errors mention app assignment: add AppRoleAssignment.ReadWrite.All to the portal app and re-consent in the customer tenant.',
            'If errors mention Could not resolve or Application.Read.All: add Application.Read.All to the portal app in the On IT tenant, re-consent in the customer tenant, php artisan cache:clear, sync again.',
            'If errors mention app role or Permission being assigned was not found: App registrations → SuperOps app → App roles → User role with Value User (not blank) → Save → Sync now.',
            'Click Sync now to apply changes.',
        ]);

        return OnboardingManual::build(
            prerequisites: $prerequisites,
            sections: array_merge($this->serverDeploySections(), [
                OnboardingManual::section(
                    'Dry run and sync',
                    'https://app.onit.ltd — this page, left column, Microsoft Entra sync',
                    $dryRunSteps,
                ),
                OnboardingManual::section(
                    'CLI alternative (server)',
                    'SSH on production host',
                    [
                        'php artisan portal:sync-entra-users --client='.$clientId.' --dry-run',
                        'php artisan portal:sync-entra-users --client='.$clientId,
                    ],
                ),
            ]),
            verify: array_values(array_filter([
                'Admin → Users — filter by this client — licensed users appear with plain M365 names.',
                'Customer Entra → Groups → On IT Portal - '.$client->name.' → Members — populated without manual adds.',
                $usesGroupScim
                    ? null
                    : 'Entra ID Free: '.$this->entraEnterpriseAppPath('SuperOps - '.$client->name).' → '.$this->entraManagePath('Users and groups').' — app users assigned by portal (SCIM scope).',
                'SuperOps → Clients → Requesters — emails match (after SCIM cycle from SuperOps SCIM step).',
            ])),
        );
    }

    /**
     * SCIM provisions SuperOps requesters from M365. SAML login is configured on the same Entra app (step 7).
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsScimGuide(string $clientName, string $groupName, bool $usesGroupScim): array
    {
        $appName = 'SuperOps - '.$clientName;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        $notes = [
            'One Entra app only: '.$appName.' — SCIM in this step, SAML in SuperOps Client SSO step on the same app. Do not create a second app.',
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
     * Client SSO (SAML) on the same Entra app created in the SCIM step.
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsClientSsoGuide(string $clientName, string $groupName, bool $usesGroupScim): array
    {
        $appName = 'SuperOps - '.$clientName;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        return OnboardingManual::build(
            prerequisites: [
                'SuperOps SCIM step complete — '.$appName.' already exists in customer Entra.',
                $usesGroupScim
                    ? 'Security group '.$groupName.' assigned to that app (from SCIM step).'
                    : 'Licensed users assigned to the app via portal sync (SuperOps Application client ID set).',
            ],
            notes: [
                'Use the same Entra app as SuperOps SCIM — do not create a new application.',
            ],
            sections: [
                OnboardingManual::section(
                    'Part A — Get SAML values from SuperOps',
                    $superOpsUrl.' — SuperOps MSP console',
                    [
                        'Settings → Requester Login → SSO Protected → Client SSO.',
                        'Click + Configuration for '.$clientName.' (or edit existing).',
                        'Copy Entity ID and Consumer Service URL (Reply URL) from SuperOps — keep this tab open.',
                    ],
                ),
                OnboardingManual::section(
                    'Part B — Configure SAML on the Entra app',
                    'https://portal.azure.com — '.$this->entraEnterpriseAppPath($appName),
                    [
                        $this->entraManagePath('Single sign-on').'.',
                        'Select SAML → Edit Basic SAML Configuration.',
                        'Identifier (Entity ID): paste Entity ID from SuperOps.',
                        'Reply URL (ACS): paste Consumer Service URL from SuperOps → Save.',
                        'Attributes & Claims → Edit → Add new claim (repeat three times).',
                        'Important: Namespace on each claim must be empty (delete the default URI prefix if present).',
                        'Claim 1: name email, source user.mail (use user.userprincipalname if the user has no mailbox).',
                        'Claim 2: name firstname, source user.givenname.',
                        'Claim 3: name lastname, source user.surname.',
                        'SAML Certificates → Certificate (Base64) → Download.',
                        'Copy only the certificate body — no -----BEGIN CERTIFICATE----- lines.',
                    ],
                ),
                OnboardingManual::section(
                    'Part C — Finish SuperOps Client SSO',
                    $superOpsUrl.' — Client SSO for '.$clientName,
                    [
                        'IDP Login URL: '.$this->entraEnterpriseAppPath($appName).' → Overview → Login URL (ends in /saml2).',
                        'Certificate: paste Base64 body → Save in SuperOps.',
                    ],
                ),
            ],
            verify: [
                'Incognito → app.onit.ltd → SuperOps tile → Microsoft sign-in with a @customer work email opens requester view (not technician role chooser).',
                'Optional direct: https://portal.onit.ltd/#/requester/login with customer Microsoft account.',
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
