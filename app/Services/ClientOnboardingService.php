<?php

namespace App\Services;

use App\Models\Client;

class ClientOnboardingService
{
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
                'who' => 'You',
                'instructions' => [
                    'Client name should match SuperOps.',
                    'Enable SuperOps SSO and Active on the left.',
                    'Click Update after changing any field.',
                ],
                'complete' => $clientExists,
                'manual' => false,
                'auto_detected' => $clientExists,
                'blocked' => false,
            ],
            [
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => 'You',
                'instructions' => [
                    'Open the SuperOps MSP console → Clients → select this customer.',
                    'Copy the Account ID from the URL (e.g. portal.onit.ltd/#/client/3425667307281944576/detail).',
                    'Paste into SuperOps Account ID on the left and click Update.',
                    'Confirm requesters exist with correct work emails (SCIM will manage them after step 5).',
                ],
                'complete' => $superopsLinked,
                'manual' => false,
                'auto_detected' => $superopsLinked,
                'blocked' => ! $clientExists,
            ],
            [
                'key' => 'pax8_linked',
                'title' => 'Link Pax8 company (optional)',
                'who' => 'You',
                'instructions' => [
                    'Skip if this client does not use the Pax8 licensing tile.',
                    'Pax8 partner portal → Companies → open customer → copy company UUID from the URL.',
                    'Paste Pax8 Company ID on the left, enable Pax8 access, and save.',
                ],
                'complete' => $pax8Configured,
                'manual' => false,
                'auto_detected' => $pax8Configured,
                'blocked' => ! $clientExists,
            ],
            [
                'key' => 'entra_group_created',
                'title' => 'M365 security group (SuperOps)',
                'who' => 'M365 admin',
                'instructions' => $this->entraGroupInstructions($groupName),
                'complete' => $entraGroupComplete,
                'manual' => true,
                'auto_detected' => $entraGroupSaved,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'superops_scim_configured',
                'title' => 'SuperOps SCIM (requesters)',
                'who' => 'M365 admin',
                'instructions' => $this->superOpsScimInstructions($client->name, $groupName),
                'complete' => (bool) ($checklist['superops_scim_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'entra_admin_consent_granted',
                'title' => 'Portal Graph admin consent',
                'who' => 'M365 admin',
                'instructions' => array_values(array_filter([
                    'Open portal.azure.com as Global Administrator in the customer tenant (not On IT\'s tenant).',
                    'Use the admin consent URL below (or open it from a ticket to the customer admin).',
                    'Review the permissions list → Accept. This grants the On IT Portal app read access in this tenant for sync and the M365 directory.',
                    'If the link fails, confirm Entra tenant ID on the left matches the customer tenant and the admin is signed into that tenant.',
                    $consentUrl ? null : 'Save the Entra tenant ID on the left to generate the consent link here.',
                ])),
                'complete' => $adminConsentComplete,
                'manual' => true,
                'auto_detected' => $syncRun,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps Client SSO (SAML)',
                'who' => 'M365 admin',
                'instructions' => $this->superOpsClientSsoInstructions($client->name, $groupName),
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'portal_sync_configured',
                'title' => 'Enable portal sync',
                'who' => 'You',
                'instructions' => array_values(array_filter([
                    'On the left: paste Entra tenant ID, enable Entra sync, then click Update.',
                    $syncEnabledGlobally ? null : 'Set ENTRA_SYNC_ENABLED=true in server .env first.',
                ])),
                'complete' => $syncConfigured,
                'manual' => false,
                'auto_detected' => $syncConfigured,
                'blocked' => ! $entraTenantSaved,
            ],
            [
                'key' => 'portal_sync_run',
                'title' => 'Run portal sync',
                'who' => 'You',
                'instructions' => [
                    'Use Dry run sync, then Sync now (buttons on the left).',
                    'Check Admin → Users — expected users should appear.',
                ],
                'complete' => $syncRun,
                'manual' => false,
                'auto_detected' => $syncRun,
                'blocked' => ! $syncConfigured || ! $syncEnabledGlobally,
            ],
            [
                'key' => 'login_tested',
                'title' => 'Test sign-in',
                'who' => 'You',
                'instructions' => [
                    'Incognito window. Use a customer work email — not a technician account.',
                    'app.onit.ltd/login → Sign in with Microsoft → dashboard loads.',
                    'SuperOps card opens requester view. portal.onit.ltd/#/requester/login works.',
                ],
                'complete' => (bool) ($checklist['login_tested'] ?? false),
                'manual' => true,
                'auto_detected' => false,
                'blocked' => ! $syncRun,
            ],
            [
                'key' => 'handed_off',
                'title' => 'Hand off to customer',
                'who' => 'You',
                'instructions' => [
                    'Tell customer: go to https://app.onit.ltd and sign in with Microsoft using work email.',
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
            'Open portal.azure.com → switch to the customer tenant (top-right — e.g. Ductec Ltd, not On IT).',
            'Microsoft Entra ID → Manage → Groups → New group.',
            'Group type: Security. Group name: '.$groupName.'.',
            'Who belongs in this group: licensed users and shared mailboxes that should be SuperOps requesters (same idea as portal, but SuperOps only sees group members).',
            'Portal sync (steps 9–10) scans the whole tenant automatically — you do NOT use this group for portal users.',
            'You do NOT add 100 people one-by-one in the UI. Pick one approach:',
            '• Entra ID P1+: Membership type Dynamic user — rule e.g. (user.userPrincipalName -contains "@customerdomain.com") so all staff auto-join.',
            '• Entra ID Free: create Assigned group, then bulk-add members once via Graph PowerShell (GDAP) — see Brain/SuperOpsEntraSync.md §1b.',
            '• Client already has requesters in SuperOps: leave them; SCIM matches by email when they enter the group (no duplicates).',
            'Putting only one admin in the group does NOT sync everyone else — SCIM provisions group members only.',
            'Create the group → Overview → copy Object ID → Entra group ID on the left → Update.',
            'Entra ID → Overview → copy Tenant ID → Entra tenant ID on the left → Update.',
        ];
    }

    /**
     * SCIM provisions SuperOps requesters from M365. This is separate from Client SSO (SAML login).
     *
     * @return list<string>
     */
    private function superOpsScimInstructions(string $clientName, string $groupName): array
    {
        return [
            'Prerequisite: security group '.$groupName.' must exist and contain the users you want as SuperOps requesters (previous step).',
            'Existing requesters in SuperOps (e.g. already listed under Clients → Requesters) are matched by email — SCIM will not duplicate them.',
            'You will create one Entra enterprise app for SCIM provisioning. Client SSO (step 7) uses a different app.',
            'Part A — SuperOps MSP console: Integrations → Microsoft Entra ID → Generate Tokens → select '.$clientName.'.',
            'Copy Tenant URL and Secret Token (Auth Token). Store securely — do not share in chat or email. Regenerate if exposed.',
            'Part B — Customer Entra: Enterprise applications → New application → non-gallery (e.g. SuperOps Provisioning - '.$clientName.') → Create.',
            'Provisioning → Mode: Automatic → Admin Credentials: Tenant URL + Secret Token from SuperOps → Test Connection → must succeed → Save.',
            'Users and groups → assign security group '.$groupName.' (not individual users — assign the group).',
            'Start provisioning. Check Entra → app → Provisioning logs after a few minutes.',
            'Verify: SuperOps → Clients → '.$clientName.' → Requesters — existing emails updated; new group members appear after SCIM cycle.',
        ];
    }

    /**
     * Client SSO (SAML) lets customer staff sign into SuperOps with their own Microsoft tenant.
     *
     * @return list<string>
     */
    private function superOpsClientSsoInstructions(string $clientName, string $groupName): array
    {
        return [
            'This is separate from the SCIM provisioning app (step 5). SCIM creates users; SAML lets them sign in with Microsoft.',
            'Part A — SuperOps MSP console: Settings → Requester Login → SSO Protected → Client SSO → + Configuration for '.$clientName.'.',
            'Copy Entity ID and Consumer Service URL (Reply URL) from SuperOps — you paste these into Entra in Part B.',
            'Part B — Customer Entra: Enterprise applications → New application → non-gallery → name e.g. SuperOps SSO - '.$clientName.' → Create.',
            'Single sign-on → SAML → Edit Basic SAML Configuration: Identifier = Entity ID from SuperOps; Reply URL = Consumer Service URL from SuperOps → Save.',
            'Attributes & Claims → add three additional claims. Namespace must be empty on each (not the default URI prefix).',
            'Claim names (exactly lowercase): email → user.mail (use user.userprincipalname if the user has no mailbox); firstname → user.givenname; lastname → user.surname.',
            'SAML Certificates → download Certificate (Base64). Copy only the certificate body — no BEGIN/END lines.',
            'Part C — Back in SuperOps Client SSO for '.$clientName.': IDP Login URL = Entra app Overview → Login URL (ends in /saml2); Certificate = paste Base64 body → Save.',
            'Entra app → Users and groups → assign '.$groupName.' (same group as SCIM).',
            'Test in a private/incognito window with a customer work email: app.onit.ltd → SuperOps tile → requester view (not the technician role chooser).',
            'SuperOps reference: support.superops.com — article "Setting up Requester SSO in SuperOps" (Client SSO section).',
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

        foreach (self::MANUAL_CHECKPOINTS as $key) {
            $current[$key] = array_key_exists($key, $checkpoints)
                ? (bool) $checkpoints[$key]
                : (bool) ($current[$key] ?? false);
        }

        $client->update(['onboarding_checklist' => $current]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldHelps(): array
    {
        return [
            'superops_account_id' => [
                'Sign in to the SuperOps MSP console (technician login).',
                'Go to Clients → open the customer.',
                'Copy the Account ID from the client profile or URL.',
                'Paste it here — must match the SuperOps client exactly.',
            ],
            'pax8_company_id' => [
                'Sign in to the Pax8 partner portal (app.pax8.com).',
                'Go to Companies → open the customer.',
                'Copy the company UUID from the URL or company profile.',
                'Paste it here and enable Pax8 access so client users see the licensing tile.',
                'Create matching company users in Pax8 (Pax8 does not offer customer Microsoft SSO yet).',
            ],
            'entra_tenant_id' => [
                'Open portal.azure.com and switch to the customer\'s Microsoft tenant (top-right directory picker).',
                'Go to Microsoft Entra ID → Overview.',
                'Copy Tenant ID (a GUID like 11111111-1111-1111-1111-111111111111).',
                'This is the customer\'s M365 directory — not On IT\'s tenant.',
            ],
            'entra_group_id' => [
                'Optional for portal sync — still recommended for SuperOps SCIM group assignment.',
                'In the customer tenant: Entra ID → Groups → On IT Portal - {Company name}.',
                'Copy Object ID if you use a group to scope who gets SuperOps SCIM provisioning.',
                'Portal sync now reads the whole tenant: licensed users and shared mailboxes.',
            ],
            'entra_sync_enabled' => [
                'Turn on after Entra tenant ID is saved (group ID optional for portal sync).',
                'Syncs licensed M365 users and shared mailboxes from the customer tenant.',
                'Display names are formatted as Jane Smith (User) or Accounts (Shared Mailbox).',
                'Shared mailboxes are synced for SuperOps records but cannot sign in to the portal.',
                'Client admins can browse the Microsoft 365 directory in the portal (People + Groups).',
                'Graph permissions: User.Read.All, LicenseAssignment.Read.All, MailboxSettings.Read, Group.Read.All.',
                'Use Dry run sync first, then Sync now, after admin consent is granted.',
            ],
        ];
    }
}
