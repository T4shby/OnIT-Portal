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

        return sprintf(
            'https://login.microsoftonline.com/%s/adminconsent?client_id=%s',
            $client->entra_tenant_id,
            $appClientId,
        );
    }

    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     who: string,
     *     instructions: list<string>,
     *     complete: bool,
     *     manual: bool,
     *     auto_detected: bool,
     *     blocked: bool,
     * }>
     */
    public function steps(Client $client): array
    {
        $checklist = $client->onboarding_checklist ?? [];
        $consentUrl = $this->adminConsentUrl($client);
        $groupName = 'On IT Portal - '.$client->name;

        $clientExists = $client->exists;
        $superopsLinked = filled($client->superops_account_id);
        $pax8Configured = ! $client->pax8_sso_enabled || filled($client->pax8_company_id);
        $entraTenantSaved = filled($client->entra_tenant_id);
        $entraGroupSaved = filled($client->entra_group_id);
        $syncConfigured = $client->hasEntraSyncConfigured();
        $syncRun = $client->entra_synced_at !== null;
        $syncEnabledGlobally = (bool) config('services.entra_sync.enabled');

        $entraGroupComplete = $entraGroupSaved || (bool) ($checklist['entra_group_created'] ?? false);
        $adminConsentComplete = (bool) ($checklist['entra_admin_consent_granted'] ?? false) || $syncRun;

        return [
            [
                'key' => 'portal_client_created',
                'title' => 'Portal client record',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => [
                    'Where: app.onit.ltd — Admin → Clients → Edit (this page).',
                    'Client name should match the SuperOps client name exactly.',
                    'On the left: enable SuperOps SSO and Active.',
                    'Click Update at the bottom of the form after any change.',
                ],
                'complete' => $clientExists,
                'manual' => false,
                'auto_detected' => $clientExists,
                'blocked' => false,
            ],
            [
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => [
                    'Where: SuperOps MSP console (technician login), then app.onit.ltd (this page).',
                    'SuperOps → Clients → open this customer.',
                    'Copy the Account ID from the browser URL (the long number after /client/ in the address bar).',
                    'On this page (left): paste into SuperOps Account ID → click Update.',
                    'SuperOps → Clients → this customer → Requesters: confirm people exist with correct @customer work emails. SCIM will match by email later — no need to delete existing requesters.',
                ],
                'complete' => $superopsLinked,
                'manual' => false,
                'auto_detected' => $superopsLinked,
                'blocked' => ! $clientExists,
            ],
            [
                'key' => 'pax8_linked',
                'title' => 'Link Pax8 company (optional)',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => [
                    'Where: Pax8 partner portal (app.pax8.com), then app.onit.ltd (this page).',
                    'Skip entirely if this client does not use the Pax8 licensing tile on the dashboard.',
                    'Pax8 → Companies → open the customer → copy the company UUID from the URL or profile.',
                    'On this page (left): paste Pax8 Company ID, enable Pax8 access → Update.',
                ],
                'complete' => $pax8Configured,
                'manual' => false,
                'auto_detected' => $pax8Configured,
                'blocked' => ! $clientExists,
            ],
            [
                'key' => 'entra_group_created',
                'title' => 'M365 security group (SuperOps)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'instructions' => $this->entraGroupInstructions($groupName),
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'superops_scim_configured',
                'title' => 'SuperOps SCIM (requesters)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'instructions' => $this->superOpsScimInstructions($client->name, $groupName),
                'complete' => (bool) ($checklist['superops_scim_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'entra_admin_consent_granted',
                'title' => 'Portal Graph admin consent',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'instructions' => $this->adminConsentInstructions($consentUrl),
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps Client SSO (SAML)',
                'who' => self::RESPONSIBLE_ON_IT_CUSTOMER_ENTRA,
                'instructions' => $this->superOpsClientSsoInstructions($client->name, $groupName),
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'portal_sync_configured',
                'title' => 'Enable portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => array_values(array_filter([
                    'Where: app.onit.ltd — this page (left column, Microsoft Entra sync section).',
                    'Entra tenant ID: customer tenant GUID (from customer Entra → Overview → Tenant ID).',
                    'Entra group ID: Object ID of the empty security group from step 04 (required for automatic group membership).',
                    'Tick Entra sync enabled → click Update.',
                    $syncEnabledGlobally ? null : 'Server: set ENTRA_SYNC_ENABLED=true in production .env, then php artisan config:clear on the server.',
                ])),
                'complete' => $syncConfigured,
                'manual' => false,
                'auto_detected' => $syncConfigured,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'portal_sync_run',
                'title' => 'Run portal sync',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => $this->portalSyncRunInstructions(),
                'complete' => $syncRun,
                'manual' => false,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ],
            [
                'key' => 'login_tested',
                'title' => 'Test sign-in',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => [
                    'Where: private/incognito browser — app.onit.ltd and SuperOps requester portal.',
                    'Do not use tom.ashby@onit.ltd or other technician accounts — use a customer work email synced in step 10.',
                    'Test 1 — Portal: https://app.onit.ltd/login → Sign in with Microsoft → dashboard loads with SuperOps and Pax8 tiles.',
                    'Test 2 — SuperOps SSO: from dashboard click SuperOps → should open requester view (not technician role chooser) → Microsoft sign-in with customer email.',
                    'Test 3 — Optional direct: https://portal.onit.ltd/#/requester/login with customer Microsoft account.',
                    'Tick Mark this step complete → Save checklist when all three behave as expected.',
                ],
                'complete' => (bool) ($checklist['login_tested'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $syncRun,
            ],
            [
                'key' => 'handed_off',
                'title' => 'Hand off to customer',
                'who' => self::RESPONSIBLE_ON_IT_PORTAL,
                'instructions' => [
                    'Where: email or ticket to the customer contact.',
                    'Tell them: go to https://app.onit.ltd and sign in with Microsoft using their work email.',
                    'They must already be a licensed M365 user (or shared mailbox record only — shared mailboxes cannot sign in).',
                    'Tick Mark this step complete → Save checklist.',
                ],
                'complete' => (bool) ($checklist['handed_off'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! ($checklist['login_tested'] ?? false),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function entraGroupInstructions(string $groupName): array
    {
        return [
            'Where: portal.azure.com — customer tenant only (switch directory top-right to the customer, e.g. Ductec Ltd, not On IT).',
            'Prerequisite (On IT tenant, once per platform): OnIT Portal for Portals app must have all 9 Graph permissions granted — you already did this if Application permissions show green ticks.',
            'Microsoft Entra ID → Groups → New group.',
            'Group type: Security. Membership type: Assigned. Group name: '.$groupName.'.',
            'Members: leave empty. Do not add anyone manually — step 10 (Sync now) fills the group automatically.',
            'Who gets added on sync: licensed M365 users and shared mailboxes (same scope as portal users). Joiners and leavers update on each hourly sync.',
            'The group is for SuperOps SCIM and SSO only. Portal user discovery reads the whole tenant — you do not add people to this group for portal login.',
            'Existing SuperOps requesters: leave them. SCIM matches by email when they enter the group — no duplicates.',
            'After Create: open the group → Overview → copy Object ID.',
            'On app.onit.ltd (this page, left): paste Object ID into Entra group ID → Update. Step 04 completes automatically when saved.',
            'If Entra tenant ID is not on the left yet: customer Entra → Overview → copy Tenant ID → paste Entra tenant ID → Update.',
        ];
    }

    /**
     * @return list<string>
     */
    private function adminConsentInstructions(?string $consentUrl): array
    {
        $lines = [
            'Where: customer tenant — use the Admin consent URL below (opens Microsoft login). Not the On IT tenant.',
            'Sign in as Global Administrator of the customer tenant (or GDAP with consent rights).',
            'The consent page must show the customer company name (e.g. Ductec Ltd), not On IT Technology Partners.',
            'Review the permissions list → click Accept.',
            'This grants the OnIT Portal for Portals app these Application permissions in the customer tenant:',
            'User.Read.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All, GroupMember.ReadWrite.All.',
            'Re-consent even if you consented before — new permissions (especially GroupMember.ReadWrite.All) are not included in old consent.',
            'Verify: customer Entra → Enterprise applications → OnIT Portal for Portals → Permissions → all show Granted.',
        ];

        if (! $consentUrl) {
            $lines[] = 'Save Entra tenant ID on the left first — the consent URL appears below this list.';
        } else {
            $lines[] = 'Click Open below (or Copy and paste into a browser). Complete Accept before step 10 Sync now.';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function portalSyncRunInstructions(): array
    {
        return [
            'Where: app.onit.ltd — this page, left column, Microsoft Entra sync section.',
            'Prerequisites: step 04 group ID saved, step 06 admin consent accepted, step 08 Entra sync enabled.',
            'Click Dry run sync first. Read the message at the top of the page.',
            'Expect: Created / Updated / Deactivated counts for portal users.',
            'Expect: SuperOps group: +N / -0 members (N = licensed users + shared mailboxes) when Entra group ID is set.',
            'If there is no SuperOps group line: Entra group ID is empty on the left — go back to step 04.',
            'If errors mention 403 or group: step 06 consent missing or GroupMember.ReadWrite.All not granted — re-consent in customer tenant.',
            'Click Sync now to apply changes.',
            'Verify portal: Admin → Users — filter by this client — licensed users appear with names like Jane Smith (User).',
            'Verify Entra: customer tenant → Groups → On IT Portal - {Company} → Members — populated without manual adds.',
            'Verify SuperOps: Clients → Requesters — emails match (after SCIM cycle from step 05).',
        ];
    }

    /**
     * SCIM provisions SuperOps requesters from M365. SAML login is configured on the same Entra app (step 7).
     *
     * @return list<string>
     */
    private function superOpsScimInstructions(string $clientName, string $groupName): array
    {
        $appName = 'SuperOps - '.$clientName;

        return [
            'Where: SuperOps MSP console, then portal.azure.com (customer tenant). Tick step complete on this page when done.',
            'Prerequisite: step 04 security group '.$groupName.' must exist (empty is fine).',
            'One Entra app only: '.$appName.' — SCIM now, SAML in step 07 on the same app. Do not create a second app.',
            'Part A — SuperOps MSP console: Integrations → Microsoft Entra ID → Generate Tokens → select '.$clientName.'.',
            'Copy Tenant URL and Secret Token (Auth Token). Store in password manager — regenerate if exposed.',
            'Part B — Customer Entra: Enterprise applications → New application → Create your own application → non-gallery.',
            'Name: '.$appName.' → Create.',
            'Left menu → Provisioning → Provisioning → Provisioning Mode: Automatic.',
            'Admin Credentials: Tenant URL = from SuperOps; Secret Token = Auth Token from SuperOps.',
            'Click Test Connection — must succeed → Save.',
            'Left menu → Users and groups → Add user/group → select security group '.$groupName.' → Assign (assign the group, not individual users).',
            'Provisioning → Start provisioning (or wait for the next cycle).',
            'Check: Entra → '.$appName.' → Provisioning → Provisioning logs — users appear after a few minutes.',
            'Check: SuperOps → Clients → '.$clientName.' → Requesters — existing emails updated; no duplicate rows.',
            'Step 07 adds SAML to this same '.$appName.' app.',
        ];
    }

    /**
     * Client SSO (SAML) on the same Entra app created in step 5.
     *
     * @return list<string>
     */
    private function superOpsClientSsoInstructions(string $clientName, string $groupName): array
    {
        $appName = 'SuperOps - '.$clientName;

        return [
            'Where: SuperOps MSP console, then portal.azure.com (customer tenant). Same app as step 05 — do not create a new application.',
            'Part A — SuperOps MSP console: Settings → Requester Login → SSO Protected → Client SSO.',
            'Click + Configuration for '.$clientName.' (or edit existing).',
            'Copy Entity ID and Consumer Service URL (Reply URL) from SuperOps — keep this tab open.',
            'Part B — Customer Entra: Enterprise applications → open '.$appName.' (created in step 05).',
            'Single sign-on → SAML → Edit Basic SAML Configuration.',
            'Identifier (Entity ID): paste Entity ID from SuperOps. Reply URL (ACS): paste Consumer Service URL from SuperOps → Save.',
            'Attributes & Claims → Edit → Add new claim (repeat three times).',
            'Important: Namespace on each claim must be empty (delete the default URI prefix if present).',
            'Claim 1: name email, source user.mail (use user.userprincipalname if the user has no mailbox).',
            'Claim 2: name firstname, source user.givenname.',
            'Claim 3: name lastname, source user.surname.',
            'SAML Certificates → Certificate (Base64) → Download. Copy only the certificate body — no -----BEGIN CERTIFICATE----- lines.',
            'Part C — Back in SuperOps Client SSO for '.$clientName.':',
            'IDP Login URL: from Entra → '.$appName.' → Overview → Login URL (ends in /saml2).',
            'Certificate: paste Base64 body → Save in SuperOps.',
            'Group '.$groupName.' was assigned in step 05 — no second assignment unless you skipped it.',
            'Test: incognito → app.onit.ltd → SuperOps tile → Microsoft sign-in with a @customer work email.',
        ];
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
     * Persist checklist ticks that match saved client fields (e.g. group ID pasted and Update clicked).
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
                'Paste here → Update. Must match the SuperOps client exactly.',
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
                'Create empty security group On IT Portal - {Company name} — do not add members.',
                'Group → Overview → Object ID. Portal sync fills members on Sync now.',
                'Required for automatic SuperOps SCIM group membership.',
            ],
            'entra_sync_enabled' => [
                'Where: this page. Turn on after Entra tenant ID is saved.',
                'Requires admin consent (step 06) before Sync now will succeed.',
                'Syncs licensed users + shared mailboxes; maintains SuperOps group when group ID is set.',
                'Use Dry run sync, then Sync now, on the left.',
            ],
        ];
    }
}
