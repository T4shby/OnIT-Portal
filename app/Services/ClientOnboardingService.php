<?php

namespace App\Services;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Support\Facades\Cache;

class ClientOnboardingService
{
    public const ENTRA_LICENSE_FREE = 'free';

    public const ENTRA_LICENSE_P1 = 'p1';

    /** @var list<string> */
    public const ENTRA_LICENSE_TIERS = [
        self::ENTRA_LICENSE_FREE,
        self::ENTRA_LICENSE_P1,
    ];

    /** Shown on checklist steps - MSP role labels, not generic "you". */
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
        'superops_scim_name_mappings',
        'superops_scim_sync_queued',
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
    public function steps(Client $client, bool $live = true): array
    {
        $checklist = $client->onboarding_checklist ?? [];
        $groupName = 'On IT Portal - '.$client->name;
        $appName = 'SuperOps - '.$client->name;
        $ssoAppName = 'SuperOps Requester SSO - '.$client->name;
        $entraTier = $this->normalizeEntraLicenseTier($client->entra_license_tier);
        $usesGroupScim = $this->usesEntraGroupScim($entraTier);
        $superOpsUrl = config('services.superops.portal_url', 'https://app.superops.ai');

        $superopsLinked = filled($client->superops_account_id);
        $pax8Configured = filled($client->pax8_company_id)
            || ! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'pax8');
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
        $scimProvisioningComplete = $this->isScimProvisioningComplete($client, $checklist, $legacyScimComplete, $live);
        $scimExportFailed = $this->isScimExportFailed($client, $live);
        $scimScopeWarnings = $this->superOpsEntraScopeWarnings($client, $live);

        $ssoComplete = (bool) ($checklist['superops_client_sso_configured'] ?? false);
        $portalSyncRunComplete = $syncRun || (bool) ($checklist['portal_sync_run'] ?? false);
        $loginTested = (bool) ($checklist['login_tested'] ?? false);

        $connectWhere = 'This checklist → orange Connect Microsoft tenant (private browser / GDAP)';

        $steps = [
            $this->withManual([
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => $superopsLinked,
                'manual' => false,
                'auto_detected' => $superopsLinked,
                'blocked' => false,
            ], OnboardingManual::build(
                automated: $superopsLinked
                    ? [
                        '**SuperOps Account ID** is saved on the left - this step is Done (auto-detected).',
                    ]
                    : [
                        'Step turns Done automatically as soon as **SuperOps Account ID** is saved (no manual tick).',
                    ],
                notes: $superopsLinked
                    ? ['Nothing left unless the wrong SuperOps client is linked - then use recovery.']
                    : ['Remaining: copy Account ID from SuperOps and paste on the left → **Save client**.'],
                sections: $superopsLinked
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: save SuperOps Account ID',
                            $superOpsUrl.' → Clients · then portal left column',
                            [
                                'SuperOps MSP → **Clients** → open **'.$client->name.'** (create first if missing).',
                                'Browser URL after `/client/` → long number = **SuperOps Account ID**.',
                                'This portal left → **SuperOps Account ID** → paste → orange **Save client**.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Wrong client or ID not found',
                        $superOpsUrl.' → Clients · portal left',
                        [
                            'Confirm SuperOps client name matches **'.$client->name.'**.',
                            'Use the number after `/client/` only - not a ticket or invoice ID.',
                            'Paste again → **Save client**. Done updates on reload.',
                        ],
                    ),
                ],
                verify: [
                    'SuperOps Account ID saved on the left; step Done automatically.',
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
                automated: $pax8Configured
                    ? [
                        filled($client->pax8_company_id ?? null)
                            ? '**Pax8 Company ID** is saved - step Done automatically.'
                            : 'Pax8 not required for this client (no Company ID) - step Done automatically.',
                    ]
                    : [
                        'Step Done automatically when Pax8 is unused, or when the Company ID is saved.',
                    ],
                notes: $pax8Configured
                    ? ['Nothing left unless Pax8 launch fails - open recovery.']
                    : [
                        'If they do not use Pax8: leave Company ID blank and licences via Pax8 off.',
                        'If they use Pax8: copy the company UUID, paste it, Save client.',
                    ],
                sections: $pax8Configured
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: skip or link Pax8',
                            'https://app.pax8.com → Companies · or portal left',
                            [
                                'No Pax8: leave **Pax8 Company ID** blank and licences via Pax8 off.',
                                'Use Pax8: app.pax8.com → **Companies** → this customer → copy company **UUID**.',
                                'Portal left → **Pax8 Company ID** → paste → **Save client**.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Pax8 tile fails or wrong company',
                        'Portal left · https://app.pax8.com',
                        [
                            'Confirm UUID matches the customer company in Pax8, not another tenant.',
                            'Re-paste **Pax8 Company ID** and **Save client**.',
                            'If launch still fails, escalate platform Pax8 SSO config (Tom) - not a customer Accept.',
                        ],
                    ),
                ],
                verify: [
                    'Pax8 unused = Done, or Pax8 Company ID is saved.',
                ],
            )),

            // Inserted product setup steps only when sold (entitled) - see filter after build.
            $this->withManual([
                'key' => 'huntress_linked',
                'title' => 'Link Huntress (when sold)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => filled($client->huntress_organization_id),
                'manual' => false,
                'auto_detected' => filled($client->huntress_organization_id),
                'blocked' => false,
                'product_key' => 'huntress',
            ], OnboardingManual::build(
                automated: filled($client->huntress_organization_id)
                    ? ['**Huntress Organization ID** saved - step Done.']
                    : ['Sold for this client: paste Huntress org ID on the Products section → Save.'],
                notes: filled($client->huntress_organization_id)
                    ? ['Nothing left unless the wrong Huntress org was linked.']
                    : ['Remaining: Products → Huntress sold on → paste Organization ID → Save client.'],
                sections: filled($client->huntress_organization_id)
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: map Huntress org',
                            'Huntress partner console · portal Products',
                            [
                                'Huntress → open customer organisation → numeric org id in the URL.',
                                'Portal left → Products → ensure Huntress sold → **Organization ID** → Save client.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Wrong org or API error',
                        'Huntress console · Integration Health',
                        [
                            'Confirm org id matches this customer.',
                            'Fix API keys on On IT if Integration Health says platform down.',
                        ],
                    ),
                ],
                verify: ['Huntress Organization ID saved; Integration Health green when platform ready.'],
            )),

            $this->withManual([
                'key' => 'dropsuite_linked',
                'title' => 'Link Dropsuite (when sold)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'complete' => filled($client->dropsuite_organization_id),
                'manual' => false,
                'auto_detected' => filled($client->dropsuite_organization_id),
                'blocked' => false,
                'product_key' => 'dropsuite',
            ], OnboardingManual::build(
                automated: filled($client->dropsuite_organization_id)
                    ? ['**Dropsuite Organization ID** saved - step Done.']
                    : ['Sold for this client: paste Dropsuite org ID on the Products section → Save.'],
                notes: filled($client->dropsuite_organization_id)
                    ? ['Nothing left unless the wrong Dropsuite org was linked.']
                    : ['Remaining: Products → Dropsuite sold on → paste Organization ID → Save client.'],
                sections: filled($client->dropsuite_organization_id)
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: map Dropsuite org',
                            'Dropsuite sub-reseller · portal Products',
                            [
                                'Dropsuite UK → organisation list → organization id for this customer.',
                                'Portal left → Products → ensure Dropsuite sold → **Organization ID** → Save client.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Wrong org or empty mailboxes',
                        'Dropsuite · Integration Health',
                        [
                            'Confirm org id under On IT reseller.',
                            'Check DROPSUITE_* tokens if Integration Health says not configured.',
                        ],
                    ),
                ],
                verify: ['Dropsuite Organization ID saved; backup tile live when platform ready.'],
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
                automated: $entraGroupComplete
                    ? [
                        'Tenant ID, licence tier (Free/P1), and portal group **'.$groupName.'** are saved on the left.',
                        'No hand-copy of Object ID when Connect / Accept succeeds.',
                    ]
                    : [
                        'One **Connect Microsoft tenant** / Accept (top of guide) writes tenant ID, licence, portal group **'.$groupName.'**, and SuperOps Entra app IDs - portal waits for Graph after Accept.',
                    ],
                notes: $entraGroupComplete
                    ? ['Nothing left on this step - expand recovery only if IDs are wrong and you need a re-run.']
                    : ['Remaining: private browser → orange **Connect Microsoft tenant** once at the top of this guide (same Accept as step 04).'],
                sections: $entraGroupComplete
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: Connect Microsoft once',
                            $connectWhere,
                            [
                                'Private/incognito browser (not your everyday On IT profile).',
                                'Click orange **Connect Microsoft tenant** at the top of this guide.',
                                'Sign in with On IT **GDAP** so Microsoft shows **'.$client->name.'** - customer tenant, not On IT first.',
                                '**Accept** Portal Graph permissions. You return here with IDs on the left (Graph waits/retries automatically).',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Connect failed or IDs still empty',
                        'This portal left column + Graph permissions on OnIT Portal for Portals',
                        [
                            'Fix Graph **Group.ReadWrite.All** / **Application.ReadWrite.All**, re-Accept if needed, or use **Retry Graph setup** (waits for Azure lag after Accept).',
                            'Legacy only: create group in customer Entra → paste **Entra license tier**, **Tenant ID**, **Group ID** → **Save client**.',
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
                automated: $adminConsentComplete
                    ? [
                        'Portal Graph Accept already recorded (or proven by a successful portal sync).',
                        'Bootstrap used that consent for group + SuperOps app shells where Graph allowed.',
                    ]
                    : [
                        'Same orange **Connect Microsoft tenant** at the top of this guide as step 03 - Accept once drives bootstrap (group, SCIM app, Client SSO shell). Graph waits/retries after Accept.',
                    ],
                notes: $adminConsentComplete
                    ? ['Nothing left unless a later bootstrap/SCIM step fails - then **Retry Graph setup** or re-Accept if permissions changed.']
                    : ['Remaining: **Accept** via the orange Connect button at the top (private browser + GDAP for **'.$client->name.'**).'],
                sections: $adminConsentComplete
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: Accept once',
                            $connectWhere,
                            [
                                'Click orange **Connect Microsoft tenant** at the top of this guide.',
                                'Private browser · GDAP for **'.$client->name.'** · **Accept**.',
                                'Left-side Entra fields and SuperOps app IDs fill when Graph succeeds (portal waits for Azure lag).',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Accept failed or apps not created',
                        $this->customerAzureWhere($client->name, 'Manage → Enterprise applications → OnIT Portal for Portals'),
                        [
                            ...$this->openCustomerAzureSteps($client->name),
                            'Enterprise applications → **OnIT Portal for Portals** → Permissions → Granted for **'.$client->name.'**.',
                            'Fix missing Graph app permissions on the platform app, then Connect again or use **Retry Graph setup**.',
                        ],
                    ),
                ],
                verify: [
                    'Accept recorded; Tenant ID on left. Use Retry Graph setup if group still empty right after Accept.',
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
                automated: [
                    'Nothing portal-side is automated here - SuperOps issues SCIM tokens only in their console (no API hand-off to us).',
                ],
                notes: $scimTokensComplete
                    ? [
                        'Marked complete - keep Tenant URL + Secret ready for step 07 Apply (or re-generate if lost). Secret is not stored on the portal.',
                    ]
                    : [
                        'Remaining: SuperOps **Generate Tokens** for **'.$client->name.'** → keep Tenant URL + Secret → tick this step → use them on step 07.',
                    ],
                sections: $scimTokensComplete
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: Generate SuperOps SCIM tokens',
                            $superOpsUrl.' → Settings / Integrations → Microsoft Entra ID',
                            [
                                'SuperOps MSP → **Integrations** → **Microsoft Entra ID** → **Generate Tokens**.',
                                'Client picker → **'.$client->name.'** (same as step 01).',
                                'Copy **Tenant URL** (SuperOps SCIM URL - not Azure Tenant ID) and **Secret Token** / Auth Token into a temp note.',
                                'This checklist → tick **Mark this step complete** (not **Save client**).',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Tokens missing, expired, or wrong client',
                        $superOpsUrl.' → Microsoft Entra ID → Generate Tokens',
                        [
                            'Re-run **Generate Tokens** for **'.$client->name.'** only.',
                            'Never paste Azure Tenant ID into SuperOps Tenant URL fields.',
                            'If SuperOps UI differs, use Integrations search for Microsoft Entra / SCIM for this client.',
                        ],
                    ),
                ],
                verify: [
                    'Tenant URL + Secret ready for '.$client->name.'; step 05 Done after the tick.',
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
                automated: filled($client->entra_superops_app_id)
                    ? [
                        'Entra app **'.$appName.'** already exists with App role Value **User**.',
                        '**SuperOps Application (client) ID** is saved on the left - step is Done when that ID is present.',
                    ]
                    : [
                        'After a successful Connect Microsoft, portal creates **'.$appName.'** (App role User) and saves the Application (client) ID.',
                    ],
                notes: filled($client->entra_superops_app_id)
                    ? ['Nothing left on this step unless you need to re-create the app (recovery).']
                    : ['Remaining: finish Connect / Accept (steps 03-04), then confirm **SuperOps Application (client) ID** on the left - or Retry Graph setup.'],
                sections: filled($client->entra_superops_app_id)
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: get SCIM Application ID on the left',
                            'Connect Microsoft / Retry Graph setup → left column',
                            [
                                'Complete **Connect Microsoft tenant** (or **Retry Graph setup**) after Graph allows Application.ReadWrite.All.',
                                'Confirm **SuperOps Application (client) ID** is filled - that auto-completes this step.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'App still missing after Connect',
                        $this->customerAzureWhere($client->name, 'Manage → Enterprise applications'),
                        [
                            'Confirm Graph **Application.ReadWrite.All** on OnIT Portal for Portals → re-Accept → **Retry Graph setup**.',
                            'Manual last resort: non-gallery enterprise app **'.$appName.'** · App role Value **User** · paste Application (client) ID on left → Save client.',
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
                'failed' => $scimExportFailed,
                'failure_summary' => $scimExportFailed
                    ? $this->scimExportFailureSummary($client, $live)
                    : null,
                'manual' => false,
                'auto_detected' => $scimProvisioningComplete,
                'blocked' => ! $scimAppComplete,
            ], $this->scimProvisioningGuide(
                $client,
                $groupName,
                $appName,
                $usesGroupScim,
                $checklist,
                $scimProvisioningComplete,
                $scimScopeWarnings,
            )),

            $this->withManual([
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps Microsoft login (Client SSO)',
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
                automated: $syncConfigured
                    ? [
                        '**Entra sync enabled** is on for this client (auto-detected from left column).',
                        'Tenant / group IDs (and Free app IDs when needed) already came from earlier steps.',
                    ]
                    : [
                        'IDs from Connect/bootstrap; you only tick enable + **Save client** - no server/.env work.',
                    ],
                notes: array_values(array_filter([
                    $syncConfigured
                        ? 'Nothing left - open recovery only if Dry run / Sync now buttons are missing.'
                        : 'Remaining: confirm IDs on the left → tick **Entra sync enabled** → **Save client**.',
                    $syncEnabledGlobally
                        ? null
                        : 'Platform sync flag is off in config - if buttons stay missing after save, message Tom.',
                ])),
                sections: $syncConfigured
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: enable portal sync',
                            'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' → left → Microsoft Entra sync',
                            array_values(array_filter([
                                'Confirm **Entra tenant ID** and **Entra group ID** are filled.',
                                $usesGroupScim
                                    ? null
                                    : 'Confirm **SuperOps Application (client) ID** is filled (Free path).',
                                'Tick **Entra sync enabled** → orange **Save client**.',
                                'After reload: **Dry run sync** and **Sync now** visible under the left form.',
                            ])),
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Buttons missing or cannot enable',
                        'Portal left · earlier checklist steps',
                        [
                            'Finish step 07 SCIM apply and confirm tenant/group IDs exist.',
                            'Save client again with **Entra sync enabled** ticked.',
                            'If still missing and config says ENTRA_SYNC is off platform-wide, message Tom - not a customer task.',
                        ],
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
                automated: $syncRun
                    ? [
                        '**Last synced** is set (or Sync has run) - step Done automatically when the portal records a sync.',
                    ]
                    : [
                        'Apply SCIM (07) queues a background Sync only when name mappings **and** Entra group ID are in place - re-run Apply if step 07 is still Pending.',
                    ],
                notes: $portalSyncRunComplete
                    ? ['Already complete - open recovery only if SuperOps names / Azure membership look wrong.']
                    : ['Remaining: left **Dry run sync** → then **Sync now** → wait a few minutes → confirm **Last synced**.'],
                warnings: $scimScopeWarnings,
                sections: $portalSyncRunComplete
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: Dry run then Sync now',
                            'https://app.onit.ltd → Admin → Clients → Edit '.$client->name.' (left buttons)',
                            [
                                'Click **Dry run sync** → read top banner (Created / Updated / SuperOps lines).',
                                'If correct, click **Sync now** (background). Wait a few minutes → refresh → **Last synced** has a time.',
                                'Optional: **Admin → Users** for this client shows licensed people.',
                                'If Last synced lags after a successful Sync now, tick **Mark this step complete**.',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Dry run / Sync banner is red',
                        'Portal left · step 04 / SCIM app roles',
                        [
                            'Re-do step 04 Accept / bootstrap if Graph permissions are the issue.',
                            'Confirm App role Value **User** on **'.$appName.'**, then dry run again.',
                            'Fix the red banner message first - do not force Sync now if Dry run is wrong.',
                        ],
                    ),
                    OnboardingManual::section(
                        'Azure or SuperOps still empty after Sync',
                        $this->customerAzureWhere($client->name, 'Groups / Enterprise apps').' · SuperOps Requesters',
                        array_values(array_filter([
                            ...$this->openCustomerAzureSteps($client->name),
                            'Groups → **'.$groupName.'** → Members should list people after portal maintain.',
                            $usesGroupScim
                                ? null
                                : 'Enterprise applications → **'.$appName.'** and **'.$ssoAppName.'** → Users and groups (Free: portal assigns after Sync now).',
                            '**'.$appName.'** → Provisioning logs - Updates after a few minutes.',
                            'SuperOps → **Clients** → **'.$client->name.'** → **Requesters** - after background Sync + SCIM, names show `(User Mailbox)` / `(Shared Mailbox)`.',
                            'Plain names: wait one cycle, step 07 name mappings recovery, then **Sync now** again.',
                        ])),
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
                automated: [
                    'Portal + SuperOps Client SSO were configured in earlier steps - this step only proves a real customer login.',
                ],
                notes: $loginTested
                    ? ['Already ticked - re-test only if something broke after a re-wire.']
                    : ['Remaining: private window, customer work email only - not an On IT account.'],
                sections: $loginTested
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: customer portal + SuperOps smoke test',
                            'Private/incognito → https://app.onit.ltd/login · then SuperOps tile',
                            [
                                'Private window → https://app.onit.ltd/login → **Sign in with Microsoft**.',
                                'Use a **customer work email** that exists under **Admin → Users** for this client (not @onit.ltd).',
                                'Dashboard loads → click **SuperOps** tile → requester portal (not technician chooser).',
                                'Tick **Mark this step complete** (not **Save client**).',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Portal login fails',
                        'Admin → Users · Connect / group membership',
                        [
                            'Confirm the user appears under **Admin → Users** for this client and is licensed.',
                            'Confirm portal group membership and sync (step 10) completed.',
                            'Do not test with On IT staff accounts.',
                        ],
                    ),
                    OnboardingManual::section(
                        'SuperOps tile fails or wrong role',
                        'Step 08 Client SSO · SuperOps Client SSO enabled',
                        [
                            'Confirm step 08 SuperOps Client SSO is Enabled for **'.$client->name.'**.',
                            'Confirm wire/Save of Login URL + cert; re-open recovery on step 08 if needed.',
                            'Must open requester portal, not SuperOps technician role picker.',
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
                automated: [
                    'All setup was done by On IT - customer never received Accept links or checklist tasks.',
                ],
                notes: (bool) ($checklist['handed_off'] ?? false)
                    ? ['Hand-off already marked complete.']
                    : ['Remaining: short message with sign-in URL only - then tick this step.'],
                sections: (bool) ($checklist['handed_off'] ?? false)
                    ? []
                    : [
                        OnboardingManual::section(
                            'Remaining: notify customer contact',
                            'Email / ticket to the customer contact',
                            [
                                'Email or ticket: open https://app.onit.ltd → **Sign in with Microsoft** → their **work email**.',
                                'Must be a licensed M365 user (shared mailboxes cannot sign in).',
                                'Tick **Mark this step complete** (not **Save client**).',
                            ],
                        ),
                    ],
                recovery: [
                    OnboardingManual::section(
                        'Customer cannot sign in after hand-off',
                        'Steps 10-11 · licence · Client SSO',
                        [
                            'Re-run step 11 smoke test with their email.',
                            'Confirm licensed mailbox and SuperOps Client SSO still Enabled for this client.',
                            'Escalate internally - do not send the customer Azure Accept or setup links.',
                        ],
                    ),
                ],
                verify: [
                    'Customer has been told how to sign in.',
                ],
            )),
        ];

        $products = app(\App\Services\Portal\ClientProductService::class);

        return array_values(array_filter(
            $steps,
            static function (array $step) use ($client, $products): bool {
                $key = $step['product_key'] ?? null;
                if ($key === null) {
                    return true;
                }

                return $products->isEntitled($client, (string) $key);
            },
        ));
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
     * @param  array<string, mixed>  $checklist
     * @param  list<string>  $scopeWarnings
     * @return array{prerequisites: list<string>, sections: list<array{title: string, where: string|null, steps: list<string>}>, verify: list<string>, notes: list<string>, automated: list<string>, recovery: list<array{title: string, where: string|null, steps: list<string>}>, warnings: list<string>}
     */
    private function scimProvisioningGuide(
        Client $client,
        string $groupName,
        string $appName,
        bool $usesGroupScim,
        array $checklist,
        bool $scimComplete,
        array $scopeWarnings,
    ): array {
        $clientName = $client->name;
        $mappingsOk = (bool) ($checklist['superops_scim_name_mappings'] ?? false);
        $syncQueued = (bool) ($checklist['superops_scim_sync_queued'] ?? false);
        $gateNotes = [];
        if (! $scimComplete) {
            if (! $mappingsOk) {
                $gateNotes[] = 'Step stays **Pending** until Apply sets **name.familyName ← extensionAttribute1**. A credentials-only Apply does not count.';
            }
            if (! $syncQueued) {
                $gateNotes[] = 'Step stays **Pending** until Apply queues portal **Sync now** (needs tenant + **Entra group ID** + platform Entra sync enabled). Save group ID first, then re-Apply SCIM.';
            }
            if ($gateNotes === []) {
                $gateNotes[] = 'Paste SuperOps tokens and **Apply SCIM credentials + start**. Done only when name mapping **and** background Sync queue both succeed.';
            }
        }

        return OnboardingManual::build(
            automated: array_values(array_filter([
                'Entra app **'.$appName.'** and App role Value **User** from Connect / bootstrap (when Graph allows).',
                $usesGroupScim
                    ? 'P1: portal group **'.$groupName.'** assigned to the SCIM enterprise app when bootstrap succeeds.'
                    : 'Free: **SCIM Application (client) ID** on the left so **Sync now** can assign users.',
                $scimComplete
                    ? 'Apply SCIM wrote tokens, set SuperOps name mappings, started provisioning, and queued background **Sync now**.'
                    : null,
            ])),
            notes: array_values(array_filter([
                $scimComplete
                    ? 'Nothing left here - SuperOps Requester suffixes still need the background Sync + SCIM Updates (step 09 proves Last synced).'
                    : 'Remaining: SuperOps **Generate Tokens** (step 05) → paste Tenant URL + Secret → **Apply SCIM credentials + start**. Secret is not stored.',
                ...$gateNotes,
            ])),
            warnings: array_values(array_filter([
                ...$scopeWarnings,
                ! $scimComplete && array_key_exists('superops_scim_name_mappings', $checklist) && ! $mappingsOk
                    ? 'Last Apply did **not** set SuperOps name mappings - step cannot go green. Re-Apply SCIM or use recovery for manual attribute mapping.'
                    : null,
                ! $scimComplete && array_key_exists('superops_scim_sync_queued', $checklist) && ! $syncQueued
                    ? 'Last Apply did **not** queue portal Sync - usually missing **Entra group ID**. Fix group, then re-Apply.'
                    : null,
            ])),
            sections: $scimComplete
                ? []
                : [
                    OnboardingManual::section(
                        'Remaining: Apply SuperOps SCIM tokens',
                        'This checklist step → form at the top',
                        [
                            'Finish step 05 if needed, then paste SuperOps **Tenant URL** and **Secret Token**.',
                            'Confirm **Entra group ID** is saved on the left (otherwise Sync will not queue and this step stays Pending).',
                            'Click **Apply SCIM credentials + start**.',
                            'Success only when flash confirms name mappings **and** Portal Sync queued - then SuperOps names take a few minutes in the **background**.',
                        ],
                    ),
                ],
            recovery: [
                OnboardingManual::section(
                    'Apply button failed - paste credentials in Azure',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Provisioning'),
                    [
                        ...$this->openCustomerAzureSteps($clientName),
                        'Enterprise applications → **'.$appName.'** → Provisioning → Automatic.',
                        'Admin Credentials → SuperOps Tenant URL + Secret → Test Connection → Save → Start provisioning.',
                        'Then re-run portal Apply so name mappings + Sync queue are recorded.',
                    ],
                ),
                OnboardingManual::section(
                    'Name mappings not auto-applied',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Provisioning → Mappings'),
                    [
                        'Attribute mapping → **Provision Microsoft Entra ID Users**.',
                        '**name.givenName** Direct ← givenName; **name.familyName** Direct ← extensionAttribute1 (default surname); **name.formatted** Direct ← displayName → Save.',
                        'Re-Apply SCIM in the portal so the checklist records name mappings.',
                    ],
                ),
                OnboardingManual::section(
                    'App role or group assign missing',
                    $this->customerAzureWhere($clientName, 'Enterprise app **'.$appName.'**'),
                    array_values(array_filter([
                        'App role Value **User** missing → App registrations → **'.$appName.'** → App roles → add enabled Value **User**.',
                        $usesGroupScim
                            ? 'Group not assigned → Users and groups → Add **'.$groupName.'**. Or **Retry Graph setup**. Ensure left **Entra group ID** is set before Apply so Sync queues.'
                            : 'Confirm left **SCIM Application (client) ID** matches App registrations → **'.$appName.'**.',
                    ])),
                ),
            ],
            verify: [
                'Step **Done** only when Apply recorded SuperOps name mappings **and** queued portal Sync (not credentials-only).',
            ],
        );
    }

    /**
     * Live Graph SCIM health, cached 5 minutes. With $live = false only the
     * cache is read (never populated), so page loads cannot trigger a Graph
     * call; WarmClientOnboardingChecksJob fills it in the background.
     *
     * @return array<string, mixed>|null
     */
    public function scimExportHealth(Client $client, bool $live = true): ?array
    {
        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_app_id)) {
            return null;
        }

        if (! $live) {
            $cached = Cache::get(self::scimHealthCacheKey($client->id));

            return is_array($cached) ? $cached : null;
        }

        return Cache::remember(self::scimHealthCacheKey($client->id), now()->addMinutes(5), function () use ($client): array {
            return app(MicrosoftGraphClient::class)->getSuperOpsScimProvisioningHealth(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
        });
    }

    public static function scimHealthCacheKey(int $clientId): string
    {
        return 'scim.health.'.$clientId;
    }

    public function isScimExportFailed(Client $client, bool $live = true): bool
    {
        $health = $this->scimExportHealth($client, $live);
        if ($health === null || ($health['needsApplyScim'] ?? false)) {
            return false;
        }

        return ! ($health['ok'] ?? false);
    }

    public function scimExportFailureSummary(Client $client, bool $live = true): string
    {
        $health = $this->scimExportHealth($client, $live);
        if ($health === null) {
            return 'SuperOps SCIM export is not healthy.';
        }

        if ($health['error'] ?? null) {
            return (string) $health['error'];
        }

        $warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
        if ($warnings !== []) {
            return (string) $warnings[0];
        }

        return 'Entra is not exporting requesters to SuperOps (Sync 1 stopped).';
    }

    public function resetScimAfterEntraAppRecreate(Client $client): void
    {
        $this->updateChecklist($client, [
            'superops_scim_provisioning' => false,
            'superops_scim_configured' => false,
            'superops_scim_name_mappings' => false,
            'superops_scim_sync_queued' => false,
        ]);
    }

    public function clearScimExportChecklistComplete(Client $client): void
    {
        $this->updateChecklist($client, [
            'superops_scim_provisioning' => false,
            'superops_scim_configured' => false,
        ]);
    }

    public function markScimExportChecklistComplete(Client $client): void
    {
        $this->updateChecklist($client, [
            'superops_scim_tokens' => true,
            'superops_scim_app' => true,
            'superops_scim_name_mappings' => true,
            'superops_scim_sync_queued' => true,
            'superops_scim_provisioning' => true,
            'superops_scim_configured' => true,
        ]);
    }

    /**
     * Step 07/08 green only when live export is OK (or not yet started), not stale checklist ticks.
     *
     * @param  array<string, mixed>  $checklist
     */
    public function isScimProvisioningComplete(Client $client, array $checklist, ?bool $legacyScimComplete = null, bool $live = true): bool
    {
        if ($this->isScimExportFailed($client, $live)) {
            return false;
        }

        $health = $this->scimExportHealth($client, $live);
        if ($health && ($health['ok'] ?? false)) {
            return true;
        }

        $legacyScimComplete ??= (bool) ($checklist['superops_scim_configured'] ?? false);
        if ($legacyScimComplete) {
            return true;
        }

        $mappings = (bool) ($checklist['superops_scim_name_mappings'] ?? false);
        $syncQueued = (bool) ($checklist['superops_scim_sync_queued'] ?? false);
        if ($mappings && $syncQueued) {
            return true;
        }

        // Pre-harden clients: provisioning tick without meta keys, and a real Sync already ran.
        if (
            (bool) ($checklist['superops_scim_provisioning'] ?? false)
            && ! array_key_exists('superops_scim_name_mappings', $checklist)
            && $client->entra_synced_at !== null
        ) {
            return true;
        }

        return false;
    }

    /**
     * SuperOps already has many requesters but Entra/portal only tracks a smaller set.
     *
     * @return list<string>
     */
    public function superOpsEntraScopeWarnings(Client $client, bool $live = true): array
    {
        $portalScoped = \App\Models\User::query()
            ->where('client_id', $client->id)
            ->whereNotNull('entra_object_id')
            ->count();

        $superOpsCount = null;
        try {
            $syncService = app(\App\Services\SuperOps\SuperOpsUserSyncService::class);
            $superOpsCount = $live
                ? $syncService->countClientRequesters($client)
                : $syncService->cachedClientRequesterCount($client);
        } catch (\Throwable) {
            $superOpsCount = null;
        }

        if ($superOpsCount === null) {
            if ($portalScoped <= 2 && filled($client->superops_account_id) && $client->entra_synced_at !== null) {
                return [
                    'Portal Entra scope is very small (**'.$portalScoped.'** identity/identities with Entra IDs) for a linked SuperOps client. SuperOps may already have a large Requester list from import/CSV - only people in **customer Entra** get `(User Mailbox)` / `(Shared Mailbox)` via SCIM. Confirm SuperOps → Requesters vs **Admin → Users** for this client.',
                ];
            }

            return [];
        }

        if ($superOpsCount < 8) {
            return [];
        }

        $threshold = max($portalScoped + 5, (int) ceil($portalScoped * 1.25));
        if ($superOpsCount <= $threshold && $portalScoped > 0) {
            return [];
        }

        if ($portalScoped === 0 && $superOpsCount >= 8) {
            return [
                "SuperOps has about **{$superOpsCount}** requesters for this client, but the portal has **no** Entra-synced users yet. Until **Sync now** loads customer Entra identities, SuperOps names stay plain and SCIM cannot apply `(User Mailbox)` / `(Shared Mailbox)` across that SuperOps book.",
            ];
        }

        return [
            "SuperOps has about **{$superOpsCount}** requesters for this client, but portal Entra sync currently tracks **{$portalScoped}**. "
            .'Only the Entra-scoped set gets last names with `(User Mailbox)` / `(Shared Mailbox)`. '
            .'Extra SuperOps rows (import/CSV/old data) stay plain until those people exist in this customer’s Entra tenant and enter the portal security group / SCIM scope.',
        ];
    }

    private function superOpsClientSsoGuide(Client $client, string $groupName, bool $usesGroupScim): array
    {
        $clientName = $client->name;
        $appName = 'SuperOps Requester SSO - '.$clientName;
        $ssoAppSaved = filled($client->entra_superops_sso_app_id);

        return OnboardingManual::build(
            automated: array_values(array_filter([
                $ssoAppSaved
                    ? 'Entra app **'.$appName.'** created and Application (client) ID saved on this client.'
                    : null,
                $ssoAppSaved && $usesGroupScim
                    ? 'Portal group **'.$groupName.'** assigned to the Client SSO enterprise app (P1) when bootstrap succeeds.'
                    : null,
                $ssoAppSaved && ! $usesGroupScim
                    ? 'Client SSO Application ID is stored so **Sync now** can assign Free-tier users (no hand-assignment).'
                    : null,
                'SAML claims (email / firstname / lastname) and token signing cert are configured when you run Wire SuperOps into Microsoft Entra.',
            ])),
            notes: $ssoAppSaved
                ? [
                    'Remaining work is only SuperOps hand-off (they do not expose Entity ID / ACS via API): copy two SuperOps URLs → wire on the form → paste Login URL + cert into SuperOps Step 3 → Save.',
                ]
                : [
                    'Connect Microsoft did not leave a Client SSO app ID - Retry Graph setup first. After that, SuperOps paste → wire → Save is the only remaining work.',
                ],
            sections: [
                OnboardingManual::section(
                    'Remaining: SuperOps ↔ Entra hand-off',
                    'Form at the top of this step + SuperOps Client SSO',
                    [
                        'SuperOps → Requester Login → SSO Protected → Client SSO → this company’s config (or + Configuration).',
                        'Copy Entity ID + Consumer service URL into the form → **Wire SuperOps into Microsoft Entra**.',
                        'Paste returned **IDP Login URL** + **Certificate** into SuperOps Step 3 → **Save** / keep Client SSO enabled.',
                        'Never reuse another customer’s Login URL or certificate.',
                    ],
                ),
            ],
            recovery: array_values(array_filter([
                ! $ssoAppSaved
                    ? OnboardingManual::section(
                        'Bootstrap did not create Client SSO app',
                        'This Edit Client page → Connect Microsoft / Retry Graph setup',
                        [
                            'Private browser + GDAP into customer tenant → Connect or Retry Graph setup.',
                            'Confirm left column **Client SSO Application (client) ID** is filled.',
                            'Then use the SuperOps hand-off form again.',
                        ],
                    )
                    : null,
                OnboardingManual::section(
                    'Wire button failed - set SAML in Azure manually',
                    $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName),
                    [
                        ...$this->openCustomerAzureSteps($clientName),
                        'Enterprise applications → **'.$appName.'** → Single sign-on → SAML.',
                        'Identifier = SuperOps Entity ID; Reply URL = SuperOps Consumer service URL → Save.',
                        'Claims: lowercase **email**, **firstname**, **lastname**.',
                        'Copy Entra Login URL + Base64 certificate into SuperOps Step 3 → Save.',
                    ],
                ),
                $usesGroupScim
                    ? OnboardingManual::section(
                        'Someone cannot Microsoft-login after Save',
                        $this->customerAzureWhere($clientName, 'Manage → Enterprise applications → '.$appName.' → Users and groups'),
                        [
                            'Confirm **'.$groupName.'** is assigned to **'.$appName.'**.',
                            'Confirm SuperOps Client SSO client binding is **'.$clientName.'** and status Enabled.',
                            'Test in a private window with a user in that group.',
                        ],
                    )
                    : OnboardingManual::section(
                        'Free: users missing on SSO app after Sync',
                        'Portal left → Sync now; Entra Users and groups on **'.$appName.'**',
                        [
                            'Confirm **Client SSO Application (client) ID** matches App registrations → **'.$appName.'**.',
                            'Run **Sync now** - do not add users by hand.',
                        ],
                    ),
            ])),
            verify: [
                'SuperOps Client SSO enabled for '.$clientName.'; portal form shows Login URL + certificate after wire; private-window requester Microsoft login works.',
            ],
        );
    }

    /**
     * Open the customer's Entra tenant for GDAP work.
     * Never "sign into On IT then switch" - private browser, land on the customer tenant.
     *
     * @return list<string>
     */
    private function openCustomerAzureSteps(string $clientName): array
    {
        return [
            'Open a **private/incognito** browser (not your normal On IT technician browser profile).',
            'Go to https://portal.azure.com and sign in with On IT **GDAP** so you land in tenant **'.$clientName.'** directly.',
            'Do **not** sign into **On IT Technology Partners LTD** first and switch directories - always open the customer tenant straight away.',
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
    public function progress(Client $client, bool $live = true): array
    {
        $steps = $this->steps($client, $live);
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

        // Keep legacy SCIM key in sync when all new SCIM substeps are truly complete.
        if (
            ($current['superops_scim_tokens'] ?? false)
            && ($current['superops_scim_app'] ?? false)
            && ($current['superops_scim_name_mappings'] ?? false)
            && ($current['superops_scim_sync_queued'] ?? false)
        ) {
            $current['superops_scim_provisioning'] = true;
            $current['superops_scim_configured'] = true;
        } elseif (
            ($current['superops_scim_tokens'] ?? false)
            && ($current['superops_scim_app'] ?? false)
            && ($current['superops_scim_provisioning'] ?? false)
            && ($current['superops_scim_name_mappings'] ?? false)
            && ($current['superops_scim_sync_queued'] ?? false)
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
                'Entra directory tier for Free vs P1 assignment (not the user SKU list alone).',
                'Microsoft 365 Business Premium (SPB) includes Entra ID P1 - choose P1, not Free.',
                'Mismatch: leave Free and the portal assigns users one-by-one; P1 uses the security group.',
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
