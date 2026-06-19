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
     *     blocked: bool,
     * }>
     */
    public function steps(Client $client): array
    {
        $checklist = $client->onboarding_checklist ?? [];
        $consentUrl = $this->adminConsentUrl($client);
        $groupName = 'On IT Portal - '.$client->name;

        $superopsLinked = filled($client->superops_account_id);
        $entraIdsSaved = filled($client->entra_tenant_id) && filled($client->entra_group_id);
        $syncConfigured = $client->hasEntraSyncConfigured();
        $syncRun = $client->entra_synced_at !== null;
        $syncEnabledGlobally = (bool) config('services.entra_sync.enabled');

        return [
            [
                'key' => 'superops_linked',
                'title' => 'Link SuperOps client',
                'who' => 'You',
                'instructions' => [
                    'Open the SuperOps MSP console → Clients → select this customer.',
                    'Copy the Account ID into the field on the left and save.',
                    'Confirm requesters exist with correct work emails (SCIM will manage them after step 4).',
                ],
                'complete' => $superopsLinked,
                'manual' => false,
                'blocked' => false,
            ],
            [
                'key' => 'portal_client_created',
                'title' => 'Portal client record',
                'who' => 'You',
                'instructions' => [
                    'Client name should match SuperOps.',
                    'Enable SuperOps SSO and set Active.',
                ],
                'complete' => true,
                'manual' => false,
                'blocked' => false,
            ],
            [
                'key' => 'entra_group_created',
                'title' => 'M365 security group',
                'who' => 'M365 admin',
                'instructions' => [
                    'In the customer Entra tenant: Entra ID → Groups → New group.',
                    'Name: '.$groupName,
                    'Add all users who need portal + SuperOps access.',
                    'Copy Tenant ID and group Object ID into the fields on the left.',
                ],
                'complete' => (bool) ($checklist['entra_group_created'] ?? false) || $entraIdsSaved,
                'manual' => true,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'superops_scim_configured',
                'title' => 'SuperOps SCIM (requesters)',
                'who' => 'M365 admin',
                'instructions' => [
                    'SuperOps → Integrations → Microsoft Entra ID → Generate Tokens → select this client.',
                    'Customer Entra → new enterprise app → Provisioning → Automatic.',
                    'Paste SuperOps Tenant URL + Auth Token → Test connection → Save.',
                    'Assign '.$groupName.' to the SCIM app.',
                ],
                'complete' => (bool) ($checklist['superops_scim_configured'] ?? false),
                'manual' => true,
                'blocked' => ! $entraIdsSaved,
            ],
            [
                'key' => 'entra_admin_consent_granted',
                'title' => 'Portal Graph admin consent',
                'who' => 'M365 admin',
                'instructions' => array_values(array_filter([
                    'Open the consent link below as Global Admin in the customer tenant.',
                    'Accept permissions so the portal can read the security group.',
                    $consentUrl ? null : 'Save the Entra tenant ID on the left to generate the consent link.',
                ])),
                'complete' => (bool) ($checklist['entra_admin_consent_granted'] ?? false),
                'manual' => true,
                'blocked' => ! $entraIdsSaved,
            ],
            [
                'key' => 'superops_client_sso_configured',
                'title' => 'SuperOps Client SSO (SAML)',
                'who' => 'M365 admin',
                'instructions' => [
                    'SuperOps → Settings → Requester Login → SSO Protected → Client SSO → + Configuration.',
                    'Customer Entra → new non-gallery SAML app with Entity ID + Reply URL from SuperOps.',
                    'Claims (lowercase): email, firstname, lastname.',
                    'Assign '.$groupName.' to the SSO app.',
                ],
                'complete' => (bool) ($checklist['superops_client_sso_configured'] ?? false),
                'manual' => true,
                'blocked' => ! $superopsLinked,
            ],
            [
                'key' => 'portal_sync_configured',
                'title' => 'Enable portal sync',
                'who' => 'You',
                'instructions' => array_values(array_filter([
                    'On the left: paste Entra tenant ID + group ID, enable Entra sync, save.',
                    $syncEnabledGlobally ? null : 'Set ENTRA_SYNC_ENABLED=true in server .env first.',
                ])),
                'complete' => $syncConfigured,
                'manual' => false,
                'blocked' => ! $entraIdsSaved,
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
                'blocked' => ! ($checklist['login_tested'] ?? false),
            ],
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
                'Paste it here so client approvers launch into their Pax8 company view.',
            ],
            'entra_tenant_id' => [
                'Open portal.azure.com and switch to the customer\'s Microsoft tenant (top-right directory picker).',
                'Go to Microsoft Entra ID → Overview.',
                'Copy Tenant ID (a GUID like 11111111-1111-1111-1111-111111111111).',
                'This is the customer\'s M365 directory — not On IT\'s tenant.',
            ],
            'entra_group_id' => [
                'In the same customer tenant: Entra ID → Groups.',
                'Create or open the group: On IT Portal - {Company name}.',
                'Open the group → copy Object ID from the overview blade.',
                'Add all users who need portal + SuperOps access to this group.',
                'The same group is used for SuperOps SCIM and portal sync.',
            ],
            'entra_sync_enabled' => [
                'Turn on after Entra tenant ID and group ID are saved.',
                'When enabled, the portal reads group members and creates/deactivates users automatically.',
                'SuperOps requesters are still managed by SuperOps SCIM (separate setup in the checklist).',
                'Use Dry run sync first, then Sync now, after admin consent is granted in the customer tenant.',
            ],
        ];
    }
}
