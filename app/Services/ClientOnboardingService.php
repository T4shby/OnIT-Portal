<?php

namespace App\Services;

use App\Models\Client;

class ClientOnboardingService
{
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
        $state = $client->exists ? 'client-'.$client->id : null;

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

        $superopsLinked = filled($client->superops_account_id);
        $pax8Configured = ! $client->pax8_sso_enabled || filled($client->pax8_company_id);
        $entraTenantSaved = filled($client->entra_tenant_id);
        $entraGroupSaved = filled($client->entra_group_id);
        $syncConfigured = $client->hasEntraSyncConfigured();
        $syncRun = $client->entra_synced_at !== null;
        $syncEnabledGlobally = (bool) config('services.entra_sync.enabled');

        $entraGroupComplete = $entraGroupSaved || (bool) ($checklist['entra_group_created'] ?? false);
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
                'title' => 'M365 security group (SuperOps)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ], $this->entraGroupGuide($groupName)),
            $this->withManual([
                'key' => 'entra_admin_consent_granted',
                'title' => 'Portal Graph admin consent',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ], $this->adminConsentGuide()),
            $this->withManual([
                'key' => 'superops_scim_configured',
                'title' => 'SuperOps SCIM (requesters)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => (bool) ($checklist['superops_scim_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ], $this->superOpsScimGuide($client->name, $groupName)),
            $this->withManual([
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps Client SSO (SAML)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $superopsLinked,
            ], $this->superOpsClientSsoGuide($client->name, $groupName)),
            $this->withManual([
                'key' => 'portal_sync_configured',
                'title' => 'Enable portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $syncConfigured,
                'manual' => false,
                'auto_detected' => $syncConfigured,
                'blocked' => ! $entraTenantSaved,
            ], $this->portalSyncConfiguredGuide($syncEnabledGlobally)),
            $this->withManual([
                'key' => 'portal_sync_run',
                'title' => 'Run portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $syncRun,
                'manual' => false,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ], $this->portalSyncRunGuide($client)),
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
    private function entraGroupGuide(string $groupName): array
    {
        return OnboardingManual::build(
            prerequisites: [
                'On IT tenant platform setup is already complete (OnIT Portal for Portals has all 9 Graph permissions). That is not part of this client checklist.',
                'You are signed into portal.azure.com as the customer tenant (e.g. Ductec Ltd), not On IT — switch directory top-right if needed.',
            ],
            sections: [
                OnboardingManual::section(
                    'Create the security group',
                    'https://portal.azure.com — customer tenant',
                    [
                        'Microsoft Entra ID → Groups → New group.',
                        'Group type: Security. Membership type: Assigned.',
                        'Group name: '.$groupName.'.',
                        'Members: leave empty — Run portal sync fills the group automatically.',
                        'After Create: open the group → Overview → copy Object ID.',
                    ],
                ),
                OnboardingManual::section(
                    'Save on the portal',
                    'https://app.onit.ltd — this page, left column',
                    [
                        'Paste Object ID into Entra group ID.',
                        'If Entra tenant ID is not set yet: customer Entra → Overview → copy Tenant ID → paste Entra tenant ID.',
                        'Click Save client. This step completes automatically when the group ID is saved.',
                    ],
                ),
            ],
            verify: [
                'Customer Entra → Groups → '.$groupName.' → Members is empty (sync will populate later).',
            ],
            notes: [
                'The group is for SuperOps SCIM and SSO only. Portal user discovery reads the whole tenant — you do not add people to this group for portal login.',
                'Who gets added on sync: licensed M365 users and shared mailboxes. Joiners and leavers update on each hourly sync — portal fills this group automatically; do not add members manually in Azure.',
                'Existing SuperOps requesters: leave them. SCIM matches by email when they enter the group — no duplicates.',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function adminConsentGuide(): array
    {
        return OnboardingManual::build(
            prerequisites: [
                'Entra tenant ID saved on the left (generates the Admin consent URL below).',
                'You have Global Administrator rights in the customer tenant (or GDAP with consent rights).',
            ],
            notes: [
                'This step is Microsoft only — not Sign in with Microsoft on app.onit.ltd.',
                'The consent page must show the customer company name (e.g. Ductec Ltd), not On IT Technology Partners.',
                'After Accept, Microsoft redirects briefly to the portal success page — that is expected, not a login failure. Step 04 ticks automatically.',
                'This grants OnIT Portal for Portals these Application permissions: User.Read.All, User.ReadWrite.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, GroupMember.ReadWrite.All, AppRoleAssignment.ReadWrite.All, Application.Read.All.',
                'Application.Read.All resolves SuperOps Application (client) ID to the enterprise app during sync — required when using client ID on Entra ID Free.',
                'User.ReadWrite.All sets extensionAttribute1 (User / Shared Mailbox) for SuperOps SCIM naming — M365 display names are never changed.',
                'Re-consent even if you consented before — new permissions (especially GroupMember.ReadWrite.All) are not included in old consent.',
            ],
            sections: [
                OnboardingManual::section(
                    'Grant admin consent',
                    'Use the Admin consent URL below (login.microsoftonline.com/adminconsent)',
                    [
                        'Copy or open the Admin consent URL below — it must contain /adminconsent.',
                        'Use a private/incognito window so you are not signed into the On IT tenant.',
                        'Sign in as a customer tenant Global Administrator.',
                        'Confirm the page shows the customer company name, not On IT.',
                        'Review the permissions list → click Accept.',
                    ],
                ),
            ],
            verify: [
                'Customer Entra → Enterprise applications → OnIT Portal for Portals → Permissions → all Application permissions show Granted.',
            ],
        );
    }

    /**
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function portalSyncConfiguredGuide(bool $syncEnabledGlobally): array
    {
        $prerequisites = [
            'M365 security group step saved (Entra group ID on the left).',
            'Portal Graph admin consent accepted in the customer tenant.',
        ];

        $sections = [
            OnboardingManual::section(
                'Enable sync on the portal',
                'https://app.onit.ltd — this page, left column, Microsoft Entra sync',
                [
                    'Entra tenant ID: customer tenant GUID (customer Entra → Overview → Tenant ID).',
                    'Entra group ID: Object ID of the empty security group from the M365 security group step.',
                    'SuperOps Application (client) ID: required on Entra ID Free — App registrations → SuperOps app → Overview → Application (client) ID (not Object ID). Leave empty when the security group is assigned to the app (Entra ID P1).',
                    'Tick Entra sync enabled.',
                    'Click Save client.',
                ],
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
    private function portalSyncRunGuide(Client $client): array
    {
        $clientId = $client->exists ? (string) $client->id : '{client-id}';

        return OnboardingManual::build(
            prerequisites: [
                'M365 security group step saved (group ID on the left).',
                'Portal Graph admin consent accepted.',
                'Entra sync enabled on the left (Enable portal sync step).',
            ],
            sections: array_merge($this->serverDeploySections(), [
                OnboardingManual::section(
                    'Dry run and sync',
                    'https://app.onit.ltd — this page, left column, Microsoft Entra sync',
                    [
                        'Click Dry run sync first. Read the green or red message at the top of the page.',
                        'Expect: Created / Updated / Deactivated counts for portal users.',
                        'Expect: SuperOps group: +N / -0 members (N = licensed users + shared mailboxes) when Entra group ID is set.',
                        'Expect: SuperOps app: +N / -0 users when SuperOps Application (client) ID is set (licensed users + shared mailboxes on Entra ID Free).',
                        'Expect: SuperOps name hints updated N (extensionAttribute1) for SCIM requester naming.',
                        'If there is no SuperOps group line: Entra group ID is empty — go back to M365 security group step.',
                        'If errors mention 403 or group: admin consent missing or GroupMember.ReadWrite.All not granted — re-consent in customer tenant.',
                        'If errors mention app assignment: add AppRoleAssignment.ReadWrite.All to the portal app and re-consent in the customer tenant.',
                        'If errors mention Could not resolve or Application.Read.All: add Application.Read.All to the portal app in the On IT tenant, re-consent in the customer tenant, php artisan cache:clear, sync again.',
                        'If errors mention app role or Permission being assigned was not found: App registrations → SuperOps app → App roles → User role with Value User (not blank) → Save → Sync now.',
                        'Click Sync now to apply changes.',
                    ],
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
            verify: [
                'Admin → Users — filter by this client — licensed users appear with plain M365 names (e.g. Jane Smith).',
                'Customer Entra → Groups → On IT Portal - {Company} → Members — populated without manual adds.',
                'Entra ID Free: SuperOps enterprise app → Users and groups — licensed users assigned automatically after Sync now (no manual adds).',
                'SuperOps → Clients → Requesters — emails match (after SCIM cycle from SuperOps SCIM step).',
            ],
        );
    }

    /**
     * SCIM provisions SuperOps requesters from M365. SAML login is configured on the same Entra app (step 7).
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsScimGuide(string $clientName, string $groupName): array
    {
        $appName = 'SuperOps - '.$clientName;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        return OnboardingManual::build(
            prerequisites: [
                'M365 security group '.$groupName.' exists (empty is fine).',
                'Portal Graph admin consent accepted before Run portal sync.',
            ],
            notes: [
                'One Entra app only: '.$appName.' — SCIM in this step, SAML in SuperOps Client SSO step on the same app. Do not create a second app.',
                'Authentication method on the provisioning screen must be Bearer authentication (Azure default).',
                'Entra ID P1: assign security group '.$groupName.' to this app once — portal sync keeps the group filled.',
                'Entra ID Free: you cannot assign groups to enterprise apps. Do not add users manually in Azure — copy the SuperOps Application (client) ID to the portal field and sync assigns users automatically.',
                'Tick Mark this step complete on this page when done.',
            ],
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
                        'Left menu → Provisioning → Provisioning → Provisioning Mode: Automatic.',
                        'Authentication method: Bearer authentication (leave selected — do not change).',
                        'Tenant URL: paste from SuperOps (not the customer Azure tenant URL).',
                        'Secret Token: paste Auth Token from SuperOps (same value — different label).',
                        'Click Test Connection — must succeed → Save.',
                        'App registrations → '.$appName.' → App roles → Create app role if none exists: Display name User, Allowed member types Users/Groups, Value User, Description Default access for SCIM users, Enable → Save (required for portal sync on Entra ID Free).',
                    ],
                ),
                OnboardingManual::section(
                    'Part C — SCIM displayName mapping (SuperOps requester names)',
                    'https://portal.azure.com — '.$appName.' → Provisioning',
                    [
                        'Provisioning → Edit attribute mapping → Provision Microsoft Entra ID Users.',
                        'Expression (copy exactly): IIF(IsNullOrEmpty([extensionAttribute1]), [displayName], Join([displayName], " (", [extensionAttribute1], ")"))',
                        'Map name.formatted → Mapping type Expression → paste expression → Always.',
                        'Map displayName → Mapping type Expression → same expression → Always → Save.',
                        'Wrong: Join("(", [displayName], [extensionAttribute1], [displayName], ")") — causes garbled (NameUserName).',
                        'Wrong: Mapping type Direct with expression in Default value if null.',
                        'Portal Sync now sets extensionAttribute1 and triggers SCIM provision-on-demand to SuperOps (not M365).',
                    ],
                ),
                OnboardingManual::section(
                    'Part D — Assign access and start provisioning',
                    'https://portal.azure.com — '.$appName,
                    [
                        'App registrations → '.$appName.' (or your SuperOps app name) → Overview → copy Application (client) ID → paste into SuperOps Application (client) ID on the portal. Do not use Object ID on that page. Required on Entra ID Free.',
                        'Entra ID P1 (preferred): Users and groups → Add user/group → Groups tab → select '.$groupName.' → Assign. Portal sync keeps group membership updated — assign the group once only.',
                        'Entra ID Free: skip manual Users and groups if portal sync assigns users — or assign '.$groupName.' group to the app.',
                        'Provisioning → Start provisioning (or wait for the next cycle).',
                        'After portal Sync now: requester names update in SuperOps automatically — no manual Provision on demand.',
                    ],
                ),
            ],
            verify: [
                'Entra → '.$appName.' → Provisioning → Attribute mapping → displayName uses Expression (not Direct).',
                'Entra → '.$appName.' → Provisioning → Provisioning logs — users appear after a few minutes.',
                'Entra ID Free: '.$appName.' → Users and groups shows licensed users after portal Sync now (no manual assignment).',
                'SuperOps → Clients → '.$clientName.' → Requesters — names like Joanne Munns (User Mailbox); emails match; no duplicate rows.',
            ],
        );
    }

    /**
     * Client SSO (SAML) on the same Entra app created in the SCIM step.
     *
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>}
     */
    private function superOpsClientSsoGuide(string $clientName, string $groupName): array
    {
        $appName = 'SuperOps - '.$clientName;
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        return OnboardingManual::build(
            prerequisites: [
                'SuperOps SCIM step complete — '.$appName.' already exists in customer Entra.',
                'Entra ID P1: security group '.$groupName.' assigned to that app. Entra ID Free: licensed users assigned via portal sync (SuperOps Entra app ID set).',
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
                    'https://portal.azure.com — Enterprise applications → '.$appName,
                    [
                        'Single sign-on → SAML → Edit Basic SAML Configuration.',
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
                        'IDP Login URL: from Entra → '.$appName.' → Overview → Login URL (ends in /saml2).',
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

        if (filled($client->entra_group_id)) {
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
    public function fieldHelps(): array
    {
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
            'entra_tenant_id' => [
                'Where: portal.azure.com (customer tenant) → paste here.',
                'Switch to the customer directory (top-right), not On IT.',
                'Microsoft Entra ID → Overview → Tenant ID (GUID).',
            ],
            'entra_group_id' => [
                'Where: portal.azure.com (customer tenant) → paste here after creating the group.',
                'Create empty security group On IT Portal - {Company name} — do not add members manually.',
                'Group → Overview → Object ID. Portal sync fills members on Sync now.',
                'Required for SuperOps SCIM group membership (portal maintains this automatically).',
            ],
            'entra_superops_app_id' => [
                'Recommended: customer Entra → App registrations → your SuperOps app → Overview → Application (client) ID.',
                'Example: 8c46a344-a010-4c78-99b9-df8b9caaba2f — paste that GUID here.',
                'Do not paste Object ID from the same Overview page — that causes Graph errors.',
                'Requires Application.Read.All on OnIT Portal for Portals (On IT tenant) + admin consent in the customer tenant.',
                'Required on Entra ID Free when Azure blocks group assignment to enterprise apps.',
                'Leave empty on Entra ID P1 when the security group is assigned to the SuperOps app instead.',
            ],
            'entra_sync_enabled' => [
                'Where: this page. Turn on after Entra tenant ID is saved.',
                'Requires admin consent (step 04) before Sync now will succeed.',
                'Syncs licensed users + shared mailboxes; maintains SuperOps group; assigns SuperOps app users when SuperOps Application (client) ID is set.',
                'Use Dry run sync, then Sync now, on the left.',
            ],
        ];
    }
}
