<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MicrosoftGraphClient
{
    private const DEFAULT_APP_ROLE_ID = '00000000-0000-0000-0000-000000000000';

    public function isConfigured(): bool
    {
        return filled(config('services.entra_sync.client_id'))
            && filled(config('services.entra_sync.client_secret'));
    }

    /**
     * Licensed member users and shared mailboxes in the customer tenant.
     *
     * @return list<array{
     *     id: string,
     *     mail: ?string,
     *     userPrincipalName: ?string,
     *     displayName: ?string,
     *     accountEnabled: bool,
     *     identityType: EntraIdentityType,
     *     licenseSkuPartNumbers: list<string>,
     *     superOpsNameHint: ?string,
     * }>
     */
    public function listSyncEligibleUsers(string $tenantId): array
    {
        $eligible = [];
        $users = $this->listTenantMemberUsers($tenantId);
        $skuPartNumbersById = $users === [] ? [] : $this->listSubscribedSkuPartNumbersById($tenantId);

        // Shared mailboxes are almost always unlicensed. Skip mailboxSettings for licensed
        // users (was N sequential Graph calls and the main Entra/M365 hang).
        $unlicensedIds = [];

        foreach ($users as $user) {
            if ($user['assignedLicenseSkuIds'] === []) {
                $unlicensedIds[] = $user['id'];
            }
        }

        $mailboxPurposes = $this->getMailboxUserPurposesBatched($tenantId, $unlicensedIds);

        foreach ($users as $user) {
            $mailboxPurpose = $mailboxPurposes[$user['id']] ?? null;
            $licenseSkuPartNumbers = array_values(array_unique(array_filter(array_map(
                static fn (string $skuId): ?string => $skuPartNumbersById[strtolower($skuId)] ?? null,
                $user['assignedLicenseSkuIds'],
            ))));

            if ($mailboxPurpose === 'shared') {
                $eligible[] = array_merge($user, [
                    'identityType' => EntraIdentityType::SharedMailbox,
                    'licenseSkuPartNumbers' => $licenseSkuPartNumbers,
                ]);

                continue;
            }

            if ($user['assignedLicenseSkuIds'] !== []) {
                $eligible[] = array_merge($user, [
                    'identityType' => EntraIdentityType::User,
                    'licenseSkuPartNumbers' => $licenseSkuPartNumbers,
                ]);
            }
        }

        return $eligible;
    }

    /**
     * Resolve mailbox userPurpose for many users via Graph JSON batch (max 20 per request).
     *
     * @param  list<string>  $userIds
     * @return array<string, string|null> userId => purpose
     */
    public function getMailboxUserPurposesBatched(string $tenantId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        $purposes = [];

        if ($userIds === []) {
            return $purposes;
        }

        foreach (array_chunk($userIds, 20) as $chunk) {
            $requests = [];

            foreach ($chunk as $index => $userId) {
                $requests[] = [
                    'id' => (string) ($index + 1),
                    'method' => 'GET',
                    'url' => "/users/{$userId}/mailboxSettings?\$select=userPurpose",
                ];
            }

            $response = $this->request($tenantId)->timeout(60)->post(
                'https://graph.microsoft.com/v1.0/$batch',
                ['requests' => $requests],
            );

            if ($response->failed()) {
                Log::warning('Microsoft Graph mailboxSettings batch failed; falling back per user', [
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                    'chunk_size' => count($chunk),
                ]);

                foreach ($chunk as $userId) {
                    $purposes[$userId] = $this->getMailboxUserPurpose($tenantId, $userId);
                }

                continue;
            }

            $responsesById = [];

            foreach ($response->json('responses') ?? [] as $item) {
                if (isset($item['id'])) {
                    $responsesById[(string) $item['id']] = $item;
                }
            }

            foreach ($chunk as $index => $userId) {
                $item = $responsesById[(string) ($index + 1)] ?? null;
                $status = (int) ($item['status'] ?? 0);

                if ($status === 404 || $item === null) {
                    $purposes[$userId] = null;

                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    Log::warning('Microsoft Graph mailboxSettings batch item failed; treating as non-shared', [
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'status' => $status,
                    ]);
                    $purposes[$userId] = null;

                    continue;
                }

                $purpose = $item['body']['userPurpose'] ?? null;
                $purposes[$userId] = is_string($purpose) ? strtolower($purpose) : null;
            }
        }

        return $purposes;
    }

    /**
     * @return list<array{id: string, mail: ?string, userPrincipalName: ?string, displayName: ?string, accountEnabled: bool, assignedLicenseSkuIds: list<string>}>
     */
    public function listTenantMemberUsers(string $tenantId): array
    {
        $users = [];
        $url = 'https://graph.microsoft.com/v1.0/users';
        $query = [
            '$filter' => "userType eq 'Member'",
            '$select' => 'id,mail,userPrincipalName,displayName,givenName,surname,accountEnabled,assignedLicenses,onPremisesExtensionAttributes,proxyAddresses,otherMails',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === 'https://graph.microsoft.com/v1.0/users' ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $user) {
                $extensionAttributes = is_array($user['onPremisesExtensionAttributes'] ?? null)
                    ? $user['onPremisesExtensionAttributes']
                    : [];

                $attributeNumber = (int) config('services.entra_sync.superops_name_extension_attribute', 1);
                $hintKey = 'extensionAttribute'.$attributeNumber;
                $currentHint = $extensionAttributes[$hintKey] ?? null;

                $users[] = [
                    'id' => (string) $user['id'],
                    'mail' => $user['mail'] ?? null,
                    'userPrincipalName' => $user['userPrincipalName'] ?? null,
                    'displayName' => $user['displayName'] ?? null,
                    'givenName' => $user['givenName'] ?? null,
                    'surname' => $user['surname'] ?? null,
                    'accountEnabled' => (bool) ($user['accountEnabled'] ?? true),
                    'assignedLicenseSkuIds' => array_values(array_filter(array_map(
                        static fn (array $license): string => (string) ($license['skuId'] ?? ''),
                        is_array($user['assignedLicenses'] ?? null) ? $user['assignedLicenses'] : [],
                    ))),
                    'superOpsNameHint' => filled($currentHint) ? (string) $currentHint : null,
                    'emailAliases' => $this->normalizeGraphEmailAliases($user),
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    /**
     * Primary + aliases for SuperOps email matching after domain/local renames.
     *
     * @param  array<string, mixed>  $user Graph user payload
     * @return list<string> lower-cased emails (unique)
     */
    public function normalizeGraphEmailAliases(array $user): array
    {
        $found = [];

        foreach (['mail', 'userPrincipalName'] as $key) {
            $value = $user[$key] ?? null;
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $found[strtolower($value)] = true;
            }
        }

        foreach ($user['otherMails'] ?? [] as $other) {
            if (is_string($other) && filter_var($other, FILTER_VALIDATE_EMAIL)) {
                $found[strtolower($other)] = true;
            }
        }

        foreach ($user['proxyAddresses'] ?? [] as $proxy) {
            if (! is_string($proxy) || $proxy === '') {
                continue;
            }
            $lower = strtolower($proxy);
            // SMTP:user@domain / smtp:user@domain
            if (str_starts_with($lower, 'smtp:')) {
                $email = substr($lower, 5);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $found[$email] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Entra directory tier used by portal Free vs P1 assignment path (not M365 "user licence" marketing alone).
     * Entra ID P1 rights show up as standalone AAD_PREMIUM* SKUs or embedded in M365 suites (e.g. Business Premium / SPB).
     */
    public function detectEntraDirectoryLicenseTier(string $tenantId): string
    {
        $partNumbers = array_map(
            static fn (string $sku): string => strtoupper($sku),
            array_values($this->listSubscribedSkuPartNumbersById($tenantId)),
        );

        foreach ($partNumbers as $sku) {
            // Explicit Entra / EMS premium directory SKUs
            if (
                str_contains($sku, 'AAD_PREMIUM')
                || $sku === 'EMS'
                || $sku === 'EMSPREMIUM'
                || $sku === 'ENTERPRISEPREMIUM'
                || $sku === 'SPE_E5'
                || $sku === 'SPE_E3'
                // Microsoft 365 suites that include Entra ID P1 (group-based app assignment)
                || $sku === 'SPB' // Microsoft 365 Business Premium (directory often shows SPB)
                || $sku === 'O365_BUSINESS_PREMIUM'
                || $sku === 'M365_BUSINESS_PREMIUM'
                || str_contains($sku, 'BUSINESS_PREMIUM')
                || $sku === 'ENTERPRISEPACK' // Microsoft 365 E3
                || $sku === 'ENTERPRISEPREMIUM_NOPSTNCONF'
            ) {
                return 'p1';
            }
        }

        return 'free';
    }

    public function findSecurityGroupIdByDisplayName(string $tenantId, string $displayName): ?string
    {
        $escaped = str_replace("'", "''", $displayName);
        $response = $this->graphGet($tenantId, 'https://graph.microsoft.com/v1.0/groups', [
            '$filter' => "displayName eq '{$escaped}'",
            '$select' => 'id,displayName,securityEnabled',
            '$top' => 5,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph group lookup failed: '.$response->status().' '.$response->body()
            );
        }

        foreach ($response->json('value') ?? [] as $group) {
            if ((bool) ($group['securityEnabled'] ?? false) && ! empty($group['id'])) {
                return (string) $group['id'];
            }
        }

        return null;
    }

    public function createSecurityGroup(string $tenantId, string $displayName, ?string $description = null): string
    {
        $mailNickname = 'onit'.substr(sha1($displayName.microtime(true)), 0, 12);

        $response = $this->graphPost($tenantId, 'https://graph.microsoft.com/v1.0/groups', [
            'displayName' => $displayName,
            'description' => $description ?? 'Managed by On IT Portal - membership filled by sync.',
            'mailEnabled' => false,
            'mailNickname' => $mailNickname,
            'securityEnabled' => true,
        ]);

        if ($response->status() === 201 && filled($response->json('id'))) {
            return (string) $response->json('id');
        }

        if ($response->status() === 403) {
            $body = $response->body();
            throw new RuntimeException(
                'Microsoft Graph cannot create security groups (HTTP 403). '
                .'Add Application permission **Group.ReadWrite.All** (or Group.Create) on **OnIT Portal for Portals** '
                .'in the On IT tenant, Grant admin consent there, then re-consent in the **customer** tenant '
                .'and use **Retry Graph setup**. Graph said: '.$this->shortGraphErrorBody($body)
            );
        }

        throw new RuntimeException(
            'Microsoft Graph create group failed: '.$response->status().' '.$this->shortGraphErrorBody($response->body())
        );
    }

    /**
     * Find or create empty security group for portal sync membership.
     */
    public function ensurePortalSecurityGroup(string $tenantId, string $displayName): string
    {
        return $this->findSecurityGroupIdByDisplayName($tenantId, $displayName)
            ?? $this->createSecurityGroup($tenantId, $displayName);
    }

    /**
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}|null
     */
    public function findApplicationByDisplayName(string $tenantId, string $displayName): ?array
    {
        $escaped = str_replace("'", "''", $displayName);
        $response = $this->graphGet($tenantId, 'https://graph.microsoft.com/v1.0/applications', [
            '$filter' => "displayName eq '{$escaped}'",
            '$select' => 'id,appId,displayName',
            '$top' => 5,
        ]);

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot list applications - Application.Read.All missing or not consented for this tenant.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph applications lookup failed: '.$response->status().' '.$response->body()
            );
        }

        $app = ($response->json('value') ?? [])[0] ?? null;

        if (! is_array($app) || empty($app['appId']) || empty($app['id'])) {
            return null;
        }

        $appId = (string) $app['appId'];

        try {
            $spId = $this->resolveEnterpriseServicePrincipalId($tenantId, $appId);
        } catch (RuntimeException) {
            $spId = $this->ensureServicePrincipalForAppId($tenantId, $appId);
        }

        return [
            'appId' => $appId,
            'applicationObjectId' => (string) $app['id'],
            'servicePrincipalId' => $spId,
        ];
    }

    /**
     * Non-gallery app registration + enterprise service principal.
     *
     * For SuperOps SCIM apps, prefer applicationTemplates instantiate - POST /applications
     * creates shells with zero synchronization templates (Apply SCIM cannot start export).
     *
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    public function createNonGalleryApplication(
        string $tenantId,
        string $displayName,
        bool $forScimProvisioning = false,
    ): array {
        if ($forScimProvisioning) {
            return $this->createNonGalleryApplicationViaTemplate($tenantId, $displayName);
        }

        $userRoleId = (string) Str::uuid();
        $response = $this->graphPost($tenantId, 'https://graph.microsoft.com/v1.0/applications', [
            'displayName' => $displayName,
            'signInAudience' => 'AzureADMyOrg',
            'appRoles' => [
                [
                    'allowedMemberTypes' => ['User'],
                    'description' => 'Default access for On IT Portal / SuperOps SCIM users',
                    'displayName' => 'User',
                    'id' => $userRoleId,
                    'isEnabled' => true,
                    'value' => 'User',
                ],
            ],
        ]);

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot create enterprise apps - add Application.ReadWrite.All '
                .'to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant, then retry Connect.'
            );
        }

        // Legacy path when POST /applications is blocked but template instantiate still works.
        if ($response->failed()) {
            return $this->createNonGalleryApplicationViaTemplate($tenantId, $displayName, $response);
        }

        $appId = (string) ($response->json('appId') ?? '');
        $applicationObjectId = (string) ($response->json('id') ?? '');

        if ($appId === '' || $applicationObjectId === '') {
            throw new RuntimeException('Microsoft Graph create app returned incomplete application payload.');
        }

        $servicePrincipalId = $this->waitForServicePrincipalForAppId($tenantId, $appId);
        $resolved = $this->waitForApplicationByAppId($tenantId, $appId, $applicationObjectId);

        return [
            'appId' => $resolved['appId'],
            'applicationObjectId' => $resolved['applicationObjectId'],
            'servicePrincipalId' => $servicePrincipalId,
        ];
    }

    /**
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    private function createNonGalleryApplicationViaTemplate(
        string $tenantId,
        string $displayName,
        ?Response $failedDirectCreate = null,
    ): array {
        // Gallery template ID for "Non-gallery" / custom LOB apps (required for SCIM provisioning UI/API).
        $templateId = '8adf8e6e-67b2-4cf2-a259-e3dc5476c621';
        $response = $this->graphPost(
            $tenantId,
            "https://graph.microsoft.com/v1.0/applicationTemplates/{$templateId}/instantiate",
            ['displayName' => $displayName],
        );

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot create enterprise apps - add Application.ReadWrite.All '
                .'to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant, then retry Connect.'
            );
        }

        if ($response->failed()) {
            $prefix = $failedDirectCreate
                ? 'Microsoft Graph create non-gallery app failed: direct='
                    .$failedDirectCreate->status().' '.$failedDirectCreate->body()
                    .'; template='
                : 'Microsoft Graph instantiate non-gallery template failed: ';

            throw new RuntimeException(
                $prefix.$response->status().' '.$response->body()
            );
        }

        $application = $response->json('application') ?? [];
        $servicePrincipal = $response->json('servicePrincipal') ?? [];
        $appId = (string) ($application['appId'] ?? '');
        $applicationObjectId = (string) ($application['id'] ?? '');
        $servicePrincipalId = (string) ($servicePrincipal['id'] ?? '');

        if ($appId === '' || $applicationObjectId === '') {
            throw new RuntimeException('Microsoft Graph create app returned incomplete application payload.');
        }

        // Prefer resolve-by-appId after create - instantiate can return SP ids not yet GET-able.
        $servicePrincipalId = $this->waitForServicePrincipalForAppId($tenantId, $appId, $servicePrincipalId);

        $resolved = $this->waitForApplicationByAppId($tenantId, $appId, $applicationObjectId);

        return [
            'appId' => $resolved['appId'],
            'applicationObjectId' => $resolved['applicationObjectId'],
            'servicePrincipalId' => $servicePrincipalId,
        ];
    }

    private function ensureServicePrincipalForAppId(string $tenantId, string $appId): string
    {
        $existing = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals(appId='{$appId}')",
            ['$select' => 'id'],
        );

        if ($existing->successful() && filled($existing->json('id'))) {
            return (string) $existing->json('id');
        }

        $create = $this->graphPost($tenantId, 'https://graph.microsoft.com/v1.0/servicePrincipals', [
            'appId' => $appId,
        ]);

        if ($create->status() === 201 && filled($create->json('id'))) {
            return (string) $create->json('id');
        }

        // Concurrent create or eventual consistency - resolve again.
        return $this->resolveEnterpriseServicePrincipalId($tenantId, $appId);
    }

    /**
     * Create/look up the enterprise SP and wait until Graph can read it (avoids 404 on appRole assign).
     */
    public function waitForServicePrincipalForAppId(
        string $tenantId,
        string $appId,
        ?string $servicePrincipalIdHint = null,
        int $maxAttempts = 12,
    ): string {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $spId = $servicePrincipalIdHint;
                if (! filled($spId)) {
                    $spId = $this->ensureServicePrincipalForAppId($tenantId, $appId);
                }

                $probe = $this->graphGet(
                    $tenantId,
                    "https://graph.microsoft.com/v1.0/servicePrincipals/{$spId}",
                    ['$select' => 'id,appId'],
                );

                if ($probe->successful() && filled($probe->json('id'))) {
                    return (string) $probe->json('id');
                }

                // Hint/SP id was stale - re-resolve via appId alias (more reliable post-create).
                $byAppId = $this->graphGet(
                    $tenantId,
                    "https://graph.microsoft.com/v1.0/servicePrincipals(appId='{$appId}')",
                    ['$select' => 'id'],
                );

                if ($byAppId->successful() && filled($byAppId->json('id'))) {
                    return (string) $byAppId->json('id');
                }

                $servicePrincipalIdHint = null;
                $lastError = 'status='.($probe->status() ?: $byAppId->status());
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $servicePrincipalIdHint = null;
            }

            if ($attempt < $maxAttempts) {
                usleep(350_000 * $attempt);
            }
        }

        throw new RuntimeException(
            'Microsoft Graph service principal for app '.$appId.' is not readable yet'
            .($lastError ? " ({$lastError})" : '')
            .'. Re-run Entra bootstrap in a few seconds.'
        );
    }

    /**
     * @return array{appId: string, applicationObjectId: string}
     */
    private function waitForApplicationByAppId(
        string $tenantId,
        string $appId,
        ?string $objectIdHint = null,
        int $maxAttempts = 8,
    ): array {
        $escapedAppId = str_replace("'", "''", $appId);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (filled($objectIdHint)) {
                $byId = $this->graphGet(
                    $tenantId,
                    "https://graph.microsoft.com/v1.0/applications/{$objectIdHint}",
                    ['$select' => 'id,appId'],
                );

                if ($byId->successful() && filled($byId->json('id')) && filled($byId->json('appId'))) {
                    return [
                        'appId' => (string) $byId->json('appId'),
                        'applicationObjectId' => (string) $byId->json('id'),
                    ];
                }
            }

            $byAppId = $this->graphGet($tenantId, 'https://graph.microsoft.com/v1.0/applications', [
                '$filter' => "appId eq '{$escapedAppId}'",
                '$select' => 'id,appId',
                '$top' => 1,
            ]);

            $row = ($byAppId->json('value') ?? [])[0] ?? null;

            if (is_array($row) && ! empty($row['id']) && ! empty($row['appId'])) {
                return [
                    'appId' => (string) $row['appId'],
                    'applicationObjectId' => (string) $row['id'],
                ];
            }

            if ($attempt < $maxAttempts) {
                usleep(250_000 * $attempt);
            }
        }

        throw new RuntimeException(
            'Microsoft Graph created app '.$appId.' but it is not yet readable (replication). Retry Connect / Re-run Entra bootstrap in a few seconds.'
        );
    }

    /**
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    /**
     * Non-gallery app by display name, or resolve an already-saved Application (client) ID first.
     *
     * Partial onboarding: clients often already have SCIM/SSO app IDs; name search can miss and
     * create then 403s even when the apps already exist - that must not look like “consent failed”.
     *
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    public function ensureNamedEnterpriseApplication(
        string $tenantId,
        string $displayName,
        ?string $knownAppClientId = null,
        bool $forScimProvisioning = false,
    ): array {
        $knownAppClientId = filled($knownAppClientId) ? strtolower(trim((string) $knownAppClientId)) : null;

        if ($knownAppClientId !== null) {
            try {
                $resolved = $this->waitForApplicationByAppId($tenantId, $knownAppClientId);
                $servicePrincipalId = $this->waitForServicePrincipalForAppId(
                    $tenantId,
                    $resolved['appId'],
                );

                return [
                    'appId' => $resolved['appId'],
                    'applicationObjectId' => $resolved['applicationObjectId'],
                    'servicePrincipalId' => $servicePrincipalId,
                ];
            } catch (Throwable $e) {
                Log::info('ensureNamedEnterpriseApplication: known appId not resolvable, fall back to name', [
                    'tenant_id' => $tenantId,
                    'known_app_id' => $knownAppClientId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $existing = $this->findApplicationByDisplayName($tenantId, $displayName);

        if ($existing !== null) {
            // Re-resolve object id in case a stale list entry races (common after partial bootstrap).
            $resolved = $this->waitForApplicationByAppId(
                $tenantId,
                $existing['appId'],
                $existing['applicationObjectId'],
            );

            // Always re-resolve SP by appId - never trust a cached SP object id that Graph 404s on.
            $servicePrincipalId = $this->waitForServicePrincipalForAppId(
                $tenantId,
                $resolved['appId'],
                $existing['servicePrincipalId'] !== '' ? $existing['servicePrincipalId'] : null,
            );

            return [
                'appId' => $resolved['appId'],
                'applicationObjectId' => $resolved['applicationObjectId'],
                'servicePrincipalId' => $servicePrincipalId,
            ];
        }

        return $this->createNonGalleryApplication($tenantId, $displayName, $forScimProvisioning);
    }

    /**
     * Ensure app role Value "User" exists (required for Free Sync app assignments).
     */
    public function ensureApplicationUserRole(string $tenantId, string $applicationObjectId, ?string $appId = null): string
    {
        if (filled($appId)) {
            $applicationObjectId = $this->waitForApplicationByAppId(
                $tenantId,
                $appId,
                $applicationObjectId,
            )['applicationObjectId'];
        } else {
            $applicationObjectId = $this->waitForApplicationObjectReadable($tenantId, $applicationObjectId);
        }

        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/applications/{$applicationObjectId}",
            ['$select' => 'id,appRoles'],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph read application roles failed: '.$response->status().' '.$response->body()
            );
        }

        $roles = is_array($response->json('appRoles')) ? $response->json('appRoles') : [];

        foreach ($roles as $role) {
            if (
                strcasecmp((string) ($role['value'] ?? ''), 'User') === 0
                && ($role['isEnabled'] ?? true)
                && ! empty($role['id'])
            ) {
                return (string) $role['id'];
            }
        }

        $roleId = (string) Str::uuid();
        $roles[] = [
            'allowedMemberTypes' => ['User'],
            'description' => 'Default access for On IT Portal / SuperOps SCIM users',
            'displayName' => 'User',
            'id' => $roleId,
            'isEnabled' => true,
            'origin' => 'Application',
            'value' => 'User',
        ];

        $patch = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/applications/{$applicationObjectId}", [
            'appRoles' => $roles,
        ]);

        if ($patch->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot update app roles - add Application.ReadWrite.All, re-consent, retry Connect.'
            );
        }

        // Directory sometimes 404s immediately after create; retry a few times.
        for ($attempt = 1; $attempt <= 4 && $patch->status() === 404; $attempt++) {
            usleep(300_000 * $attempt);
            $patch = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/applications/{$applicationObjectId}", [
                'appRoles' => $roles,
            ]);
        }

        if ($patch->failed() && $patch->status() !== 204) {
            throw new RuntimeException(
                'Microsoft Graph create app role User failed: '.$patch->status().' '.$patch->body()
            );
        }

        return $roleId;
    }

    private function waitForApplicationObjectReadable(string $tenantId, string $applicationObjectId, int $maxAttempts = 8): string
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/applications/{$applicationObjectId}",
                ['$select' => 'id'],
            );

            if ($response->successful() && filled($response->json('id'))) {
                return (string) $response->json('id');
            }

            if ($attempt < $maxAttempts) {
                usleep(250_000 * $attempt);
            }
        }

        return $applicationObjectId;
    }

    public function assignGroupToEnterpriseApp(
        string $tenantId,
        string $servicePrincipalId,
        string $groupId,
        string $appRoleId,
        ?string $appId = null,
    ): void {
        $spId = $servicePrincipalId;
        $roleCandidates = array_values(array_unique(array_filter([
            $appRoleId,
            self::DEFAULT_APP_ROLE_ID,
        ])));
        $lastBody = '';

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            if (filled($appId)) {
                try {
                    $spId = $this->waitForServicePrincipalForAppId($tenantId, $appId, $spId !== '' ? $spId : null, maxAttempts: 5);
                } catch (Throwable $e) {
                    $lastBody = $e->getMessage();
                    if ($attempt >= 12) {
                        throw new RuntimeException(
                            'Microsoft Graph assign group to enterprise app failed resolving service principal: '.$lastBody
                        );
                    }
                    usleep(min(3_000_000, 700_000 * $attempt));

                    continue;
                }

                // Role patch on Application often lags SP - re-pick assignable role each few tries.
                if ($attempt === 1 || $attempt % 3 === 0) {
                    try {
                        $resolved = $this->resolveAssignableAppRoleId($tenantId, $spId, $appId);
                        array_unshift($roleCandidates, $resolved);
                        $roleCandidates = array_values(array_unique(array_filter($roleCandidates)));
                    } catch (Throwable) {
                        // keep previous candidates
                    }
                }
            }

            foreach ($roleCandidates as $roleId) {
                $response = $this->graphPost($tenantId, "https://graph.microsoft.com/v1.0/groups/{$groupId}/appRoleAssignments", [
                    'principalId' => $groupId,
                    'resourceId' => $spId,
                    'appRoleId' => $roleId,
                ]);

                if ($response->status() === 201) {
                    return;
                }

                if ($response->status() === 400 && (
                    str_contains($response->body(), 'already exists')
                    || str_contains($response->body(), 'Permission being assigned already exists')
                )) {
                    return;
                }

                $lastBody = $response->body();

                if ($response->status() === 403) {
                    throw new RuntimeException(
                        'Microsoft Graph cannot assign group to app - AppRoleAssignment.ReadWrite.All missing or not consented.'
                    );
                }

                // Wrong role / lag on this SP - try next candidate role, then outer backoff.
                if ($response->status() === 404 || $response->status() === 400) {
                    continue;
                }

                if (! in_array($response->status(), [429, 502, 503, 504], true)) {
                    throw new RuntimeException(
                        'Microsoft Graph assign group to enterprise app failed: '.$response->status().' '
                        .$this->shortGraphErrorBody($lastBody)
                    );
                }
            }

            if ($attempt >= 12) {
                break;
            }

            // Stale SP id - force re-lookup by appId next loop.
            $spId = '';
            usleep(min(3_000_000, 800_000 * $attempt));
        }

        throw new RuntimeException(
            'Microsoft Graph assign group to enterprise app failed after retries: '
            .$this->shortGraphErrorBody($lastBody !== '' ? $lastBody : 'unknown')
            .' Use Retry Graph setup later, or assign the portal group to the Client SSO app in Entra (Users and groups).'
        );
    }

    public function listTenantGroups(string $tenantId): array
    {
        $groups = [];
        $url = 'https://graph.microsoft.com/v1.0/groups';
        $query = [
            '$select' => 'id,displayName,mail,mailEnabled,securityEnabled,groupTypes,description',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === 'https://graph.microsoft.com/v1.0/groups' ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph groups request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $group) {
                $groups[] = [
                    'id' => (string) $group['id'],
                    'displayName' => $group['displayName'] ?? null,
                    'mail' => $group['mail'] ?? null,
                    'mailEnabled' => (bool) ($group['mailEnabled'] ?? false),
                    'securityEnabled' => (bool) ($group['securityEnabled'] ?? false),
                    'groupTypes' => $group['groupTypes'] ?? [],
                    'description' => $group['description'] ?? null,
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $groups;
    }

    /**
     * @return array<string, string> lowercase skuId => skuPartNumber
     */
    public function listSubscribedSkuPartNumbersById(string $tenantId): array
    {
        $response = $this->request($tenantId)
            ->get('https://graph.microsoft.com/v1.0/subscribedSkus', [
                '$select' => 'skuId,skuPartNumber',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph subscribedSkus failed: '.$response->status().' '.$response->body()
            );
        }

        $skus = [];

        foreach ($response->json('value') ?? [] as $sku) {
            $skuId = strtolower((string) ($sku['skuId'] ?? ''));
            $skuPartNumber = (string) ($sku['skuPartNumber'] ?? '');

            if ($skuId !== '' && $skuPartNumber !== '') {
                $skus[$skuId] = $skuPartNumber;
            }
        }

        return $skus;
    }

    /**
     * @return list<array{skuPartNumber: string, consumedUnits: int, prepaidEnabled: int, utilizationPct: float}>
     */
    public function listSubscribedSkuInventory(string $tenantId): array
    {
        $response = $this->request($tenantId)
            ->get('https://graph.microsoft.com/v1.0/subscribedSkus', [
                '$select' => 'skuId,skuPartNumber,consumedUnits,prepaidUnits,appliesTo,capabilityStatus',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph subscribedSkus inventory failed: '.$response->status().' '.$response->body()
            );
        }

        $inventory = [];

        foreach ($response->json('value') ?? [] as $sku) {
            if (($sku['appliesTo'] ?? null) !== 'User' || ($sku['capabilityStatus'] ?? null) !== 'Enabled') {
                continue;
            }

            $skuPartNumber = trim((string) ($sku['skuPartNumber'] ?? ''));
            if ($skuPartNumber === '') {
                continue;
            }

            $consumedUnits = max(0, (int) ($sku['consumedUnits'] ?? 0));
            $prepaidEnabled = max(0, (int) ($sku['prepaidUnits']['enabled'] ?? 0));

            $inventory[] = [
                'skuPartNumber' => $skuPartNumber,
                'consumedUnits' => $consumedUnits,
                'prepaidEnabled' => $prepaidEnabled,
                'utilizationPct' => $prepaidEnabled > 0
                    ? round(($consumedUnits / $prepaidEnabled) * 100, 1)
                    : 0.0,
            ];
        }

        return $inventory;
    }

    /**
     * @return list<string>
     */
    public function getUserLicenseSkuPartNumbers(string $tenantId, string $userId): array
    {
        $response = $this->request($tenantId)
            ->get("https://graph.microsoft.com/v1.0/users/{$userId}/licenseDetails");

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph licenseDetails failed for user '.$userId.': '.$response->status().' '.$response->body()
            );
        }

        $skus = [];

        foreach ($response->json('value') ?? [] as $license) {
            if (! empty($license['skuPartNumber'])) {
                $skus[] = (string) $license['skuPartNumber'];
            }
        }

        return $skus;
    }

    public function hasActiveLicense(string $tenantId, string $userId): bool
    {
        return $this->getUserLicenseSkuPartNumbers($tenantId, $userId) !== [];
    }

    public function getMailboxUserPurpose(string $tenantId, string $userId): ?string
    {
        $url = "https://graph.microsoft.com/v1.0/users/{$userId}/mailboxSettings";
        $query = ['$select' => 'userPurpose'];

        $response = $this->graphGetWithTransientRetry($tenantId, $url, $query);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            Log::warning('Microsoft Graph mailboxSettings failed; treating user as non-shared mailbox', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $purpose = $response->json('userPurpose');

        return is_string($purpose) ? strtolower($purpose) : null;
    }

    /**
     * @deprecated Use listSyncEligibleUsers() - group scope retained for SCIM reference only.
     *
     * @return list<array{id: string, mail: ?string, userPrincipalName: ?string, displayName: ?string, accountEnabled: bool}>
     */
    public function listGroupUsers(string $tenantId, string $groupId): array
    {
        $users = [];
        $url = "https://graph.microsoft.com/v1.0/groups/{$groupId}/transitiveMembers/microsoft.graph.user";
        $query = [
            '$select' => 'id,mail,userPrincipalName,displayName,accountEnabled',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === "https://graph.microsoft.com/v1.0/groups/{$groupId}/transitiveMembers/microsoft.graph.user" ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $user) {
                if (($user['@odata.type'] ?? '') !== '' && ! Str::contains($user['@odata.type'] ?? '', 'user')) {
                    continue;
                }

                $users[] = [
                    'id' => (string) $user['id'],
                    'mail' => $user['mail'] ?? null,
                    'userPrincipalName' => $user['userPrincipalName'] ?? null,
                    'displayName' => $user['displayName'] ?? null,
                    'accountEnabled' => (bool) ($user['accountEnabled'] ?? true),
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    /**
     * @return list<string>
     */
    public function listGroupMemberUserIds(string $tenantId, string $groupId): array
    {
        $ids = [];
        $url = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members/microsoft.graph.user";
        $query = ['$select' => 'id', '$top' => 999];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === "https://graph.microsoft.com/v1.0/groups/{$groupId}/members/microsoft.graph.user" ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph group members request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $member) {
                if (! empty($member['id'])) {
                    $ids[] = (string) $member['id'];
                }
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $ids;
    }

    public function addGroupMember(string $tenantId, string $groupId, string $userId): void
    {
        $response = $this->request($tenantId)
            ->post("https://graph.microsoft.com/v1.0/groups/{$groupId}/members/\$ref", [
                '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$userId}",
            ]);

        if ($response->status() === 204 || $response->status() === 201) {
            return;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'already exist')) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph add group member failed: '.$response->status().' '.$response->body()
        );
    }

    public function removeGroupMember(string $tenantId, string $groupId, string $userId): void
    {
        $response = $this->request($tenantId)
            ->delete("https://graph.microsoft.com/v1.0/groups/{$groupId}/members/{$userId}/\$ref");

        if ($response->status() === 204 || $response->status() === 404) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph remove group member failed: '.$response->status().' '.$response->body()
        );
    }

    /**
     * Resolve the enterprise application (service principal) Object ID in the customer tenant.
     * Accepts service principal ID, app registration Object ID, or application (client) ID.
     */
    public function resolveEnterpriseServicePrincipalId(string $tenantId, string $idOrAppId): string
    {
        $idOrAppId = trim($idOrAppId);

        if ($idOrAppId === '') {
            throw new RuntimeException('SuperOps Entra app ID is empty.');
        }

        $servicePrincipalResponse = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$idOrAppId}",
            ['$select' => 'id,displayName,appId'],
        );

        if ($servicePrincipalResponse->successful()) {
            return (string) $servicePrincipalResponse->json('id');
        }

        if (! in_array($servicePrincipalResponse->status(), [403, 404], true)) {
            throw new RuntimeException(
                'Microsoft Graph service principal lookup failed: '.$servicePrincipalResponse->status().' '.$servicePrincipalResponse->body()
            );
        }

        $byAppIdResponse = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals(appId='{$idOrAppId}')",
            ['$select' => 'id,displayName,appId'],
        );

        if ($byAppIdResponse->successful()) {
            return (string) $byAppIdResponse->json('id');
        }

        if ($byAppIdResponse->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot resolve SuperOps app by Application (client) ID - Application.Read.All is missing or not consented. '
                .'Add Application.Read.All to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant (checklist step 04), then php artisan cache:clear and sync again.'
            );
        }

        $applicationResponse = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/applications/{$idOrAppId}",
            ['$select' => 'id,appId,displayName'],
        );

        if ($applicationResponse->successful()) {
            $appId = (string) $applicationResponse->json('appId');

            $fromRegistrationResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals(appId='{$appId}')",
                ['$select' => 'id,displayName,appId'],
            );

            if ($fromRegistrationResponse->successful()) {
                return (string) $fromRegistrationResponse->json('id');
            }
        }

        throw new RuntimeException(
            'Could not resolve SuperOps enterprise app in this tenant. '
            .'Paste Application (client) ID from App registrations → SuperOps → Overview (not Object ID). '
            .'If the client ID is correct, add Application.Read.All to the portal app and re-consent in the customer tenant.'
        );
    }

    /**
     * @return array<string, string> userId => appRoleAssignmentId
     */
    public function listAppAssignedUsers(string $tenantId, string $servicePrincipalId): array
    {
        $assignments = [];
        $url = "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/appRoleAssignedTo";
        $query = ['$top' => 999];

        while ($url) {
            $response = $this->graphGet($tenantId, $url, $url === "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/appRoleAssignedTo" ? $query : []);

            if ($response->failed()) {
                if ($response->status() === 403) {
                    throw new RuntimeException(
                        'Microsoft Graph app role assignments request failed: 403. '
                        .'Paste the SuperOps Application (client) ID from App registrations → Overview - not the Object ID on that page. '
                        .'Also confirm AppRoleAssignment.ReadWrite.All is granted for the customer tenant on OnIT Portal for Portals.'
                    );
                }

                throw new RuntimeException(
                    'Microsoft Graph app role assignments request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $assignment) {
                if (($assignment['principalType'] ?? '') !== 'User' || empty($assignment['principalId'])) {
                    continue;
                }

                $assignments[(string) $assignment['principalId']] = (string) $assignment['id'];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $assignments;
    }

    public function resolveAssignableAppRoleId(
        string $tenantId,
        string $servicePrincipalId,
        ?string $appId = null,
    ): string {
        $lastError = null;
        $spId = $servicePrincipalId;

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            if (filled($appId)) {
                try {
                    $spId = $this->waitForServicePrincipalForAppId($tenantId, $appId, $spId, maxAttempts: 3);
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                    $spId = $servicePrincipalId;
                }
            }

            $response = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$spId}",
                ['$select' => 'appRoles,displayName'],
            );

            if ($response->successful()) {
                $appRoles = $response->json('appRoles') ?? [];
                $selectedRoleId = $this->pickBestAssignableAppRoleId($appRoles);

                if ($selectedRoleId !== null) {
                    return $selectedRoleId;
                }

                // SP replicated but roles lag the Application patch - fall through to application roles.
                if (filled($appId)) {
                    $fromApp = $this->resolveAssignableAppRoleIdFromApplication($tenantId, $appId);
                    if ($fromApp !== null) {
                        return $fromApp;
                    }
                }

                $lastError = 'no assignable app roles on SP yet';
            } else {
                $lastError = 'status='.$response->status().' '.$response->body();
                // Force appId re-lookup next loop when SP id 404s.
                if ($response->status() === 404) {
                    $spId = '';
                }
            }

            if ($attempt < 10) {
                usleep(400_000 * $attempt);
            }
        }

        if (filled($appId)) {
            $fromApp = $this->resolveAssignableAppRoleIdFromApplication($tenantId, $appId);
            if ($fromApp !== null) {
                return $fromApp;
            }
        }

        throw new RuntimeException(
            'Microsoft Graph could not read SuperOps app roles'
            .($lastError ? ': '.$lastError : '.')
            .' Create App role User on the app registration if missing, then Re-run Entra bootstrap.'
        );
    }

    private function resolveAssignableAppRoleIdFromApplication(string $tenantId, string $appId): ?string
    {
        $escapedAppId = str_replace("'", "''", $appId);
        $response = $this->graphGet($tenantId, 'https://graph.microsoft.com/v1.0/applications', [
            '$filter' => "appId eq '{$escapedAppId}'",
            '$select' => 'id,appRoles',
            '$top' => 1,
        ]);

        if ($response->failed()) {
            return null;
        }

        $row = ($response->json('value') ?? [])[0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        return $this->pickBestAssignableAppRoleId(is_array($row['appRoles'] ?? null) ? $row['appRoles'] : []);
    }

    /**
     * @param  list<array<string, mixed>>  $appRoles
     */
    private function pickBestAssignableAppRoleId(array $appRoles): ?string
    {
        $bestRoleId = null;
        $bestScore = PHP_INT_MIN;

        foreach ($appRoles as $role) {
            if (! ($role['isEnabled'] ?? false)) {
                continue;
            }

            if (! in_array('User', $role['allowedMemberTypes'] ?? [], true)) {
                continue;
            }

            $roleId = (string) ($role['id'] ?? '');

            if ($roleId === '') {
                continue;
            }

            $value = trim((string) ($role['value'] ?? ''));
            $displayName = strtolower((string) ($role['displayName'] ?? ''));
            $description = strtolower((string) ($role['description'] ?? ''));

            if ($displayName === 'msiam_access' || $value === 'msiam_access') {
                continue;
            }

            $score = 0;

            if ($value !== '') {
                $score += 10;
            }

            if ($value === 'User') {
                $score += 25;
            }

            if (str_contains($displayName, 'scim') || str_contains($description, 'scim')) {
                $score += 15;
            }

            if ($roleId === self::DEFAULT_APP_ROLE_ID) {
                $score += 5;
            }

            if ($value === '' && $displayName === 'user') {
                $score -= 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRoleId = $roleId;
            }
        }

        return $bestRoleId;
    }

    public function assignUserToEnterpriseApp(
        string $tenantId,
        string $servicePrincipalId,
        string $userId,
        string $appRoleId,
    ): void {
        $response = $this->graphPost($tenantId, "https://graph.microsoft.com/v1.0/users/{$userId}/appRoleAssignments", [
            'principalId' => $userId,
            'resourceId' => $servicePrincipalId,
            'appRoleId' => $appRoleId,
        ]);

        if ($response->status() === 201) {
            return;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'Permission being assigned already exists')) {
            return;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'Permission being assigned was not found on application')) {
            throw new RuntimeException(
                'Microsoft Graph assign user to enterprise app failed: 400 - no matching app role on the SuperOps app. '
                .'Create an app role on App registrations → SuperOps → App roles (Display name User, Users/Groups), then sync again.'
            );
        }

        throw new RuntimeException(
            'Microsoft Graph assign user to enterprise app failed: '.$response->status().' '.$response->body()
        );
    }

    public function assignUserToEnterpriseAppDefaultAccess(
        string $tenantId,
        string $servicePrincipalId,
        string $userId,
    ): void {
        $this->assignUserToEnterpriseApp(
            $tenantId,
            $servicePrincipalId,
            $userId,
            self::DEFAULT_APP_ROLE_ID,
        );
    }

    public function removeUserFromEnterpriseApp(
        string $tenantId,
        string $servicePrincipalId,
        string $appRoleAssignmentId,
    ): void {
        $response = $this->request($tenantId)
            ->delete("https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/appRoleAssignedTo/{$appRoleAssignmentId}");

        if ($response->status() === 204 || $response->status() === 404) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph remove user from enterprise app failed: '.$response->status().' '.$response->body()
        );
    }

    public function updateUserDisplayName(string $tenantId, string $userId, string $displayName): void
    {
        $response = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/users/{$userId}", [
            'displayName' => $displayName,
        ]);

        if ($response->status() === 204) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph update user displayName failed: '.$response->status().' '.$response->body()
        );
    }

    /**
     * Resolve the SCIM synchronization job and user rule for provision-on-demand.
     *
     * @return array{jobId: string, userRuleId: string}
     */
    public function resolveSuperOpsScimProvisioningContext(string $tenantId, string $servicePrincipalId): array
    {
        $cacheKey = 'entra_scim_provision_ctx.'.$tenantId.'.'.$servicePrincipalId;

        return Cache::remember($cacheKey, now()->addDay(), function () use ($tenantId, $servicePrincipalId) {
            $jobsResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs",
            );

            if ($jobsResponse->status() === 403) {
                throw new RuntimeException(
                    'Microsoft Graph cannot read SuperOps provisioning jobs - Synchronization.ReadWrite.All is missing or not consented. '
                    .'Add Synchronization.ReadWrite.All to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant (checklist step 04), then php artisan cache:clear and sync again.'
                );
            }

            if ($jobsResponse->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph synchronization jobs request failed: '.$jobsResponse->status().' '.$jobsResponse->body()
                );
            }

            $jobs = $jobsResponse->json('value') ?? [];

            if ($jobs === []) {
                throw new RuntimeException(
                    'No SCIM provisioning job found on the SuperOps enterprise app. '
                    .'In Entra → Provisioning → set mode to Automatic, test connection, save, then start provisioning.'
                );
            }

            $jobId = $this->pickSynchronizationJobId($jobs);

            $schemaResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$jobId}/schema",
            );

            if ($schemaResponse->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph synchronization schema request failed: '.$schemaResponse->status().' '.$schemaResponse->body()
                );
            }

            $userRuleId = $this->pickUserSynchronizationRuleId($schemaResponse->json('synchronizationRules') ?? []);

            if ($userRuleId === null) {
                throw new RuntimeException(
                    'Could not find a User synchronization rule on the SuperOps SCIM job. '
                    .'Confirm provisioning is configured and attribute mapping includes displayName.'
                );
            }

            return [
                'jobId' => $jobId,
                'userRuleId' => $userRuleId,
            ];
        });
    }

    /**
     * @param  list<string>  $userIds
     */
    public function provisionUsersOnDemand(
        string $tenantId,
        string $servicePrincipalId,
        string $jobId,
        string $ruleId,
        array $userIds,
    ): int {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return 0;
        }

        $batchSize = max(1, (int) config('services.entra_sync.superops_provision_batch_size', 1));
        $intervalUs = max(0, (int) config('services.entra_sync.superops_provision_interval_us', 2_100_000));
        $provisioned = 0;

        foreach (array_chunk($userIds, $batchSize) as $index => $chunk) {
            if ($index > 0 && $intervalUs > 0) {
                usleep($intervalUs);
            }

            $subjects = array_map(
                static fn (string $userId): array => [
                    'objectId' => $userId,
                    'objectTypeName' => 'User',
                ],
                $chunk,
            );

            $response = $this->postProvisionOnDemand(
                $tenantId,
                $servicePrincipalId,
                $jobId,
                $ruleId,
                $subjects,
            );

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph SuperOps provision on demand failed: '.$response->status().' '.$response->body()
                );
            }

            $provisioned += $this->countProvisionOnDemandSubjects($response, count($chunk));
        }

        return $provisioned;
    }

    /**
     * @param  list<array{objectId: string, objectTypeName: string}>  $subjects
     */
    private function postProvisionOnDemand(
        string $tenantId,
        string $servicePrincipalId,
        string $jobId,
        string $ruleId,
        array $subjects,
    ): Response {
        $url = "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$jobId}/provisionOnDemand";
        $payload = [
            'parameters' => [
                [
                    'ruleId' => $ruleId,
                    'subjects' => $subjects,
                ],
            ],
        ];

        $maxAttempts = max(1, (int) config('services.entra_sync.superops_provision_max_attempts', 3));
        // provisionOnDemand often needs longer than the default Graph 30s timeout (cURL 28 / 0 bytes).
        $response = $this->request($tenantId)->timeout(90)->post($url, $payload);

        for ($attempt = 2; $attempt <= $maxAttempts && $response->status() === 429; $attempt++) {
            usleep(2_100_000 * ($attempt - 1));
            $response = $this->request($tenantId)->timeout(90)->post($url, $payload);
        }

        return $response;
    }

    private function countProvisionOnDemandSubjects(Response $response, int $requested): int
    {
        $results = $response->json('value');

        if (! is_array($results) || $results === []) {
            return $requested;
        }

        $accepted = 0;

        foreach ($results as $result) {
            $state = strtolower((string) ($result['state'] ?? ''));

            if ($state !== '' && ! in_array($state, ['skipped', 'failed', 'quarantine'], true)) {
                $accepted++;
            }
        }

        return $accepted > 0 ? $accepted : $requested;
    }

    public function clearSuperOpsScimProvisioningContextCache(string $tenantId, string $servicePrincipalId): void
    {
        Cache::forget('entra_scim_provision_ctx.'.$tenantId.'.'.$servicePrincipalId);
    }

    /**
     * True when the Entra SCIM app is missing, half-deleted, or Graph cannot provision it.
     */
    public function superOpsScimEnterpriseAppNeedsRecreate(string $tenantId, string $appId): bool
    {
        $tenantId = strtolower(trim($tenantId));
        $appId = trim($appId);

        if ($tenantId === '' || $appId === '') {
            return false;
        }

        try {
            $servicePrincipalId = $this->resolveEnterpriseServicePrincipalId($tenantId, $appId);
        } catch (Throwable) {
            // Orphaned Application (client) ID or deleted enterprise SP - must recreate.
            return true;
        }

        if ($this->countScimSynchronizationTemplates($tenantId, $servicePrincipalId) === 0) {
            return true;
        }

        foreach ($this->fetchScimSynchronizationJobs($tenantId, $servicePrincipalId) as $job) {
            $id = (string) ($job['id'] ?? '');
            if ($id !== '' && ! $this->isUsableScimJobId($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete any existing SuperOps SCIM shell (by id and/or display name), wait for Graph,
     * then create a brand-new non-gallery app - never reuse a half-deleted registration.
     *
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    public function recreateNamedEnterpriseApplication(
        string $tenantId,
        string $displayName,
        ?string $knownAppClientId = null,
    ): array {
        $tenantId = strtolower(trim($tenantId));
        $idsToDelete = [];

        if (filled($knownAppClientId)) {
            $idsToDelete[] = strtolower(trim((string) $knownAppClientId));
        }

        try {
            $byName = $this->findApplicationByDisplayName($tenantId, $displayName);
            if ($byName !== null && filled($byName['appId'] ?? null)) {
                $idsToDelete[] = strtolower((string) $byName['appId']);
            }
        } catch (Throwable $e) {
            Log::warning('SCIM recreate: name lookup before delete failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        foreach (array_values(array_unique($idsToDelete)) as $appId) {
            try {
                $this->deleteEnterpriseApplicationByAppId($tenantId, $appId);
            } catch (Throwable $e) {
                Log::warning('SCIM recreate: delete failed', [
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $stillThere = null;
            try {
                $stillThere = $this->findApplicationByDisplayName($tenantId, $displayName);
            } catch (Throwable) {
                break;
            }

            if ($stillThere === null) {
                break;
            }

            usleep(1_000_000);
        }

        return $this->createNonGalleryApplication($tenantId, $displayName, forScimProvisioning: true);
    }
    public function deleteEnterpriseApplicationByAppId(string $tenantId, string $appId): void
    {
        $tenantId = strtolower(trim($tenantId));
        $appId = trim($appId);

        try {
            $resolved = $this->waitForApplicationByAppId($tenantId, $appId, null, 4);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '404') || str_contains(strtolower($e->getMessage()), 'not found')) {
                return;
            }
            throw $e;
        }

        try {
            $servicePrincipalId = $this->waitForServicePrincipalForAppId($tenantId, $appId, maxAttempts: 4);
            Cache::forget('scim.job_id.'.$servicePrincipalId);
            $deleteSp = $this->graphDelete(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}",
            );
            if ($deleteSp->failed() && $deleteSp->status() !== 404) {
                throw new RuntimeException(
                    'Could not delete SuperOps enterprise application: '.$deleteSp->status().' '
                    .$this->shortGraphErrorBody($deleteSp->body())
                );
            }
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '404') && ! str_contains(strtolower($e->getMessage()), 'not found')) {
                Log::warning('SCIM app SP delete skipped or failed', [
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deleteApp = $this->graphDelete(
            $tenantId,
            "https://graph.microsoft.com/v1.0/applications/{$resolved['applicationObjectId']}",
        );
        if ($deleteApp->failed() && $deleteApp->status() !== 404) {
            throw new RuntimeException(
                'Could not delete SuperOps app registration: '.$deleteApp->status().' '
                .$this->shortGraphErrorBody($deleteApp->body())
            );
        }
    }

    /**
     * Read Entra SCIM provisioning health for a customer's SuperOps enterprise app.
     *
     * @return array{
     *     ok: bool,
     *     servicePrincipalId: string,
     *     jobId: string,
     *     jobCount: int,
     *     jobState: string,
     *     hasProvisioningJob: bool,
     *     hasScimSecrets: bool,
     *     needsApplyScim: bool,
     *     needsRepair: bool,
     *     scimBaseAddress: string,
     *     details: list<string>,
     *     warnings: list<string>,
     *     error: string|null
     * }
     */
    public function getSuperOpsScimProvisioningHealth(string $tenantId, string $entraSuperopsAppId): array
    {
        $tenantId = strtolower(trim($tenantId));
        $entraSuperopsAppId = trim($entraSuperopsAppId);
        $details = [];
        $warnings = [];

        if ($tenantId === '' || $entraSuperopsAppId === '') {
            return $this->scimHealthResult(
                ok: false,
                servicePrincipalId: '',
                jobId: '',
                jobCount: 0,
                jobState: '',
                hasProvisioningJob: false,
                hasScimSecrets: false,
                needsApplyScim: true,
                needsRepair: false,
                scimBaseAddress: '',
                details: $details,
                warnings: $warnings,
                error: 'Entra tenant ID and SuperOps SCIM Application (client) ID are required.',
            );
        }

        try {
            $servicePrincipalId = $this->resolveEnterpriseServicePrincipalId($tenantId, $entraSuperopsAppId);
        } catch (Throwable $e) {
            return $this->scimHealthResult(
                ok: false,
                servicePrincipalId: '',
                jobId: '',
                jobCount: 0,
                jobState: '',
                hasProvisioningJob: false,
                hasScimSecrets: false,
                needsApplyScim: false,
                needsRepair: false,
                scimBaseAddress: '',
                details: $details,
                warnings: $warnings,
                error: $e->getMessage(),
            );
        }

        $jobs = $this->fetchScimSynchronizationJobs($tenantId, $servicePrincipalId);
        $jobCount = count($jobs);
        $jobId = $this->pickSynchronizationJobId($jobs);
        $jobState = $this->extractSynchronizationJobState($jobs, $jobId);

        $secrets = $this->readScimSynchronizationSecrets($tenantId, $servicePrincipalId);
        $scimBaseAddress = trim((string) ($secrets['BaseAddress'] ?? ''));
        $hasScimSecrets = $scimBaseAddress !== '';
        $hasProvisioningJob = $jobId !== '';

        if ($jobCount === 0) {
            $warnings[] = 'No SCIM provisioning job in Entra - Sync 1 (SuperOps export) is stopped even if portal Sync 2 still assigns users to the app.';
        } elseif ($jobCount > 1) {
            $warnings[] = 'Multiple SCIM provisioning jobs found - portal reuses one; avoid creating extra jobs in the Entra UI.';
        }

        if (! $hasScimSecrets) {
            $warnings[] = 'SuperOps SCIM Tenant URL is not stored in Entra - Apply SCIM with Tenant URL + Secret Token from SuperOps step 05.';
        }

        $needsApplyScim = ! $hasScimSecrets;
        $needsRepair = ! $hasProvisioningJob
            || in_array($jobState, ['quarantine', 'notstarted'], true);
        $ok = $hasProvisioningJob && $hasScimSecrets && ! $needsRepair;

        if ($hasProvisioningJob) {
            $details[] = 'Provisioning job: '.$jobId.($jobState !== '' ? ' ('.$jobState.')' : '');
        }

        if ($hasScimSecrets) {
            $details[] = 'SCIM BaseAddress configured in Entra';
        }

        return $this->scimHealthResult(
            ok: $ok,
            servicePrincipalId: $servicePrincipalId,
            jobId: $jobId,
            jobCount: $jobCount,
            jobState: $jobState,
            hasProvisioningJob: $hasProvisioningJob,
            hasScimSecrets: $hasScimSecrets,
            needsApplyScim: $needsApplyScim,
            needsRepair: $needsRepair,
            scimBaseAddress: $scimBaseAddress,
            details: $details,
            warnings: $warnings,
            error: null,
        );
    }

    /**
     * Recreate/start the Entra SCIM provisioning job when credentials already exist in Entra.
     * Does not require re-pasting the SuperOps secret unless BaseAddress is missing.
     *
     * @return array{
     *     jobId: string,
     *     servicePrincipalId: string,
     *     started: bool,
     *     nameMappingsConfigured: bool,
     *     details: list<string>,
     *     warnings: list<string>
     * }
     */
    public function repairSuperOpsScimProvisioning(string $tenantId, string $entraSuperopsAppId): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $tenantId = strtolower(trim($tenantId));
        $entraSuperopsAppId = trim($entraSuperopsAppId);
        $details = [];
        $warnings = [];

        $servicePrincipalId = $this->resolveEnterpriseServicePrincipalId($tenantId, $entraSuperopsAppId);
        $details[] = 'Service principal resolved';

        $secrets = $this->readScimSynchronizationSecrets($tenantId, $servicePrincipalId);
        if (trim((string) ($secrets['BaseAddress'] ?? '')) === '') {
            throw new RuntimeException(
                'SuperOps SCIM Tenant URL is not stored in Entra. '
                .'Use Admin → Clients → Edit → Apply SCIM with Tenant URL + Secret Token from SuperOps step 05.'
            );
        }

        $details[] = 'Existing SCIM BaseAddress found in Entra';

        $this->discardStaleScimJobCache($tenantId, $servicePrincipalId);

        $jobId = $this->ensureScimSynchronizationJob($tenantId, $servicePrincipalId);
        $details[] = 'Provisioning job: '.$jobId;

        $nameMappingsConfigured = false;
        try {
            $waited = $this->waitUntilScimSchemaReady($tenantId, $servicePrincipalId, $jobId);
            if ($waited['probes'] > 1) {
                $details[] = 'Waited for SCIM schema ('.$waited['probes'].' probes)';
            }
            $jobId = $waited['jobId'];

            $mappingResult = $this->ensureSuperOpsScimNameAttributeMappings($tenantId, $servicePrincipalId, $jobId);
            $nameMappingsConfigured = $mappingResult['configured'];
            $details = array_merge($details, $mappingResult['details']);
        } catch (Throwable $e) {
            Log::warning('SCIM repair: name mappings not applied', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Name attribute mapping not auto-applied: '.$e->getMessage()
                .' - re-run repair after schema is ready, or set name.familyName Direct ← extensionAttribute1 in Entra.';
        }

        $started = $this->startScimSynchronizationJob($tenantId, $servicePrincipalId, $jobId);
        $details[] = $started
            ? 'Start provisioning requested'
            : 'Job exists - Start provisioning may already be running (check Entra Provisioning logs)';

        $this->clearSuperOpsScimProvisioningContextCache($tenantId, $servicePrincipalId);

        return [
            'jobId' => $jobId,
            'servicePrincipalId' => $servicePrincipalId,
            'started' => $started,
            'nameMappingsConfigured' => $nameMappingsConfigured,
            'details' => $details,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ensure a SCIM provisioning job exists, write SuperOps Tenant URL + secret into Entra,
     * set SuperOps name mappings (familyName ← extensionAttribute1), then start the job.
     * Secret is never stored in our database.
     *
     * @return array{
     *     jobId: string,
     *     servicePrincipalId: string,
     *     started: bool,
     *     nameMappingsConfigured: bool,
     *     details: list<string>,
     *     warnings: list<string>
     * }
     */
    public function applySuperOpsScimCredentials(
        string $tenantId,
        string $entraSuperopsAppId,
        string $scimTenantUrl,
        string $scimSecretToken,
    ): array {
        // Ensure / wait-for-job / schema can run longer than default PHP request limits.
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $tenantId = strtolower(trim($tenantId));
        $scimTenantUrl = rtrim(trim($scimTenantUrl), '/');
        $scimSecretToken = trim($scimSecretToken);
        $details = [];
        $warnings = [];

        if ($scimTenantUrl === '' || $scimSecretToken === '') {
            throw new RuntimeException('SuperOps SCIM Tenant URL and Secret Token are required.');
        }

        $servicePrincipalId = $this->resolveEnterpriseServicePrincipalId($tenantId, $entraSuperopsAppId);
        $details[] = 'Service principal resolved';

        $this->discardStaleScimJobCache($tenantId, $servicePrincipalId);

        $jobId = $this->ensureScimSynchronizationJob($tenantId, $servicePrincipalId);
        $details[] = 'Provisioning job: '.$jobId;

        $this->putScimSynchronizationSecrets($tenantId, $servicePrincipalId, $scimTenantUrl, $scimSecretToken);
        $details[] = 'SCIM BaseAddress + SecretToken written to Entra';

        $nameMappingsConfigured = false;
        try {
            // Brand-new non-gallery apps often return ProvisioningTaskNotFound for schema for ~10-30s.
            $waited = $this->waitUntilScimSchemaReady($tenantId, $servicePrincipalId, $jobId);
            if ($waited['probes'] > 1) {
                $details[] = 'Waited for SCIM schema ('.$waited['probes'].' probes)';
            }
            $jobId = $waited['jobId'];

            $mappingResult = $this->ensureSuperOpsScimNameAttributeMappings($tenantId, $servicePrincipalId, $jobId);
            $nameMappingsConfigured = $mappingResult['configured'];
            $details = array_merge($details, $mappingResult['details']);
        } catch (Throwable $e) {
            Log::warning('SCIM SuperOps name mappings failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Name attribute mapping not auto-applied after wait: '.$e->getMessage()
                .' - re-run Apply SCIM once more, or set name.familyName Direct ← extensionAttribute1 in Entra Provisioning.';
        }

        $started = $this->startScimSynchronizationJob($tenantId, $servicePrincipalId, $jobId);
        $details[] = $started
            ? 'Start provisioning requested'
            : 'Job credentials saved - Start provisioning may already be running (check Entra if needed)';

        $this->clearSuperOpsScimProvisioningContextCache($tenantId, $servicePrincipalId);

        return [
            'jobId' => $jobId,
            'servicePrincipalId' => $servicePrincipalId,
            'started' => $started,
            'nameMappingsConfigured' => $nameMappingsConfigured,
            'details' => $details,
            'warnings' => $warnings,
        ];
    }

    /**
     * Poll until SCIM job schema is readable (or re-pick job id if the list changes).
     *
     * @return array{jobId: string, probes: int}
     */
    private function waitUntilScimSchemaReady(
        string $tenantId,
        string $servicePrincipalId,
        string $jobId,
        int $maxAttempts = 16,
    ): array {
        $currentJobId = $jobId;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Refresh job id from Graph - create can return an id before the task is fully registered.
            try {
                $resolved = $this->resolveCurrentScimJobId($tenantId, $servicePrincipalId);
                if ($resolved !== '') {
                    $currentJobId = $resolved;
                }
            } catch (Throwable) {
                // keep previous id
            }

            $schemaResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$currentJobId}/schema",
            );

            if ($schemaResponse->successful()) {
                return ['jobId' => $currentJobId, 'probes' => $attempt];
            }

            $lastError = $schemaResponse->status().' '.$this->shortGraphErrorBody($schemaResponse->body());
            $body = $schemaResponse->body();
            $retryable = $schemaResponse->status() === 404
                || str_contains($body, 'ProvisioningTaskNotFound')
                || str_contains($body, 'Template is not supported')
                || in_array($schemaResponse->status(), [429, 502, 503, 504], true);

            if (! $retryable) {
                throw new RuntimeException('Microsoft Graph SCIM schema not ready: '.$lastError);
            }

            if ($attempt < $maxAttempts) {
                // ~2s, 2.5s, … capped at 3s - typically 15-40s total for first Apply on a new app.
                usleep(min(3_000_000, 1_500_000 + (250_000 * $attempt)));
            }
        }

        throw new RuntimeException(
            'Microsoft Graph SCIM schema still not ready after waiting: '.($lastError ?? 'unknown')
        );
    }

    private function resolveCurrentScimJobId(string $tenantId, string $servicePrincipalId): string
    {
        $jobsResponse = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs",
        );

        if ($jobsResponse->failed()) {
            return '';
        }

        $jobs = $jobsResponse->json('value') ?? [];

        return $this->pickSynchronizationJobId(is_array($jobs) ? $jobs : []);
    }

    /**
     * Align SCIM name mappings with the known SuperOps pattern:
     * portal writes surname + (User Mailbox)/(Shared Mailbox) to extensionAttribute1;
     * SCIM maps name.familyName from that attribute.
     *
     * @return array{configured: bool, details: list<string>}
     */
    public function ensureSuperOpsScimNameAttributeMappings(
        string $tenantId,
        string $servicePrincipalId,
        string $jobId,
    ): array {
        $schemaResponse = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$jobId}/schema",
        );

        if ($schemaResponse->failed()) {
            throw new RuntimeException(
                'Microsoft Graph read SCIM schema failed: '.$schemaResponse->status().' '
                .$this->shortGraphErrorBody($schemaResponse->body())
            );
        }

        $schema = $schemaResponse->json();
        if (! is_array($schema)) {
            throw new RuntimeException('Microsoft Graph SCIM schema response was empty.');
        }

        $desired = [
            // Direct only - no expression and no default [surname]. Empty extensionAttribute1
            // must not invent plain surnames (that produced SuperOps "email Palmer").
            // Hybrid users get SuperOps names via SuperOps API when Graph cannot write this attribute.
            'familyname' => [
                'sourceName' => 'extensionAttribute1',
                'defaultValue' => null,
                'clearDefault' => true,
            ],
            'givenname' => [
                'sourceName' => 'givenName',
                'defaultValue' => null,
            ],
            'formatted' => [
                'sourceName' => 'displayName',
                'defaultValue' => null,
            ],
        ];

        $changed = false;
        $touched = [];
        $rules = $schema['synchronizationRules'] ?? [];

        if (! is_array($rules)) {
            throw new RuntimeException('SCIM schema has no synchronizationRules.');
        }

        foreach ($rules as $ruleIndex => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $objectMappings = $rule['objectMappings'] ?? [];
            if (! is_array($objectMappings)) {
                continue;
            }

            foreach ($objectMappings as $mapIndex => $objectMapping) {
                if (! is_array($objectMapping)) {
                    continue;
                }

                $sourceObject = strtolower((string) ($objectMapping['sourceObjectName'] ?? ''));
                $targetObject = strtolower((string) ($objectMapping['targetObjectName'] ?? ''));

                if (! str_contains($sourceObject, 'user') && ! str_contains($targetObject, 'user')) {
                    continue;
                }
                if (str_contains($sourceObject, 'group') || str_contains($targetObject, 'group')) {
                    continue;
                }

                $attributeMappings = $objectMapping['attributeMappings'] ?? [];
                if (! is_array($attributeMappings)) {
                    continue;
                }

                foreach ($attributeMappings as $attrIndex => $attributeMapping) {
                    if (! is_array($attributeMapping)) {
                        continue;
                    }

                    $targetName = (string) ($attributeMapping['targetAttributeName'] ?? '');
                    $target = strtolower($targetName);
                    $desiredKey = null;

                    if (str_contains($target, 'familyname') || $target === 'surname' || str_ends_with($target, 'family_name')) {
                        $desiredKey = 'familyname';
                    } elseif (str_contains($target, 'givenname') || $target === 'given_name' || $target === 'firstname') {
                        $desiredKey = 'givenname';
                    } elseif (str_contains($target, 'formatted') || str_contains($target, 'displayname')) {
                        $desiredKey = 'formatted';
                    }

                    if ($desiredKey === null || $targetName === '') {
                        // Graph rejects unknown properties on write - keep original mapping only.
                        $attributeMappings[$attrIndex] = $this->sanitizeAttributeMappingForWrite($attributeMapping);

                        continue;
                    }

                    $want = $desired[$desiredKey];
                    $source = is_array($attributeMapping['source'] ?? null) ? $attributeMapping['source'] : [];
                    $currentSourceName = strtolower((string) ($source['name'] ?? ''));
                    $currentExpression = strtolower((string) ($source['expression'] ?? ''));
                    $currentDefault = (string) ($attributeMapping['defaultValue'] ?? '');
                    $sourceType = strtolower((string) ($source['type'] ?? 'attribute'));

                    $alreadyOk = (
                        $sourceType !== 'expression'
                        && (
                            $currentSourceName === strtolower((string) $want['sourceName'])
                            || str_contains($currentExpression, strtolower((string) $want['sourceName']))
                        )
                    );

                    $needsDefault = ($want['defaultValue'] ?? null) !== null
                        && $currentDefault !== (string) $want['defaultValue'];

                    // Strip Entra default [surname] / expressions for familyName - no inventing last names.
                    $mustClearDefault = ! empty($want['clearDefault'])
                        && $currentDefault !== ''
                        && ($want['defaultValue'] ?? null) === null;
                    $mustDropExpression = ! empty($want['clearDefault'])
                        && ($sourceType === 'expression' || str_contains($currentExpression, 'append'));

                    if ($alreadyOk && ! $needsDefault && ! $mustClearDefault && ! $mustDropExpression) {
                        $attributeMappings[$attrIndex] = $this->sanitizeAttributeMappingForWrite($attributeMapping);

                        continue;
                    }

                    $attributeMappings[$attrIndex] = $this->sanitizeAttributeMappingForWrite([
                        'defaultValue' => $want['defaultValue'],
                        'exportMissingReferences' => $attributeMapping['exportMissingReferences'] ?? false,
                        'flowBehavior' => $attributeMapping['flowBehavior'] ?? 'FlowWhenChanged',
                        'flowType' => 'Always',
                        'matchingPriority' => $attributeMapping['matchingPriority'] ?? 0,
                        'source' => [
                            'expression' => '['.$want['sourceName'].']',
                            'name' => $want['sourceName'],
                            'parameters' => [],
                            'type' => 'Attribute',
                        ],
                        'targetAttributeName' => $targetName,
                    ]);

                    $changed = true;
                    $touched[] = $targetName.' ← '.$want['sourceName']
                        .(! empty($want['clearDefault']) ? ' (no default / no expression fallback)' : '');
                }

                $objectMappings[$mapIndex]['attributeMappings'] = $attributeMappings;
            }

            $rules[$ruleIndex]['objectMappings'] = $objectMappings;
        }

        if (! $changed) {
            return [
                'configured' => true,
                'details' => ['SCIM name mappings already use extensionAttribute1 for SuperOps last names'],
            ];
        }

        // Strip illegal read-only / UI-only fields Graph rejects on PUT of the full schema.
        $schema = $this->sanitizeSynchronizationSchemaForWrite($schema);
        $schema['synchronizationRules'] = $rules;

        $put = $this->graphPut(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$jobId}/schema",
            $schema,
        );

        if ($put->failed() && $put->status() !== 204) {
            throw new RuntimeException(
                'Microsoft Graph update SCIM schema failed: '.$put->status().' '.$put->body()
            );
        }

        return [
            'configured' => true,
            'details' => [
                'SCIM SuperOps name mappings updated: '.implode('; ', array_unique($touched)),
            ],
        ];
    }

    /**
     * Graph attributeMapping has no mappingType - only defaultValue, flowBehavior, flowType, source, targetAttributeName, …
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function sanitizeAttributeMappingForWrite(array $mapping): array
    {
        $source = is_array($mapping['source'] ?? null) ? $mapping['source'] : [];

        $out = [
            'defaultValue' => $mapping['defaultValue'] ?? null,
            'exportMissingReferences' => (bool) ($mapping['exportMissingReferences'] ?? false),
            'flowBehavior' => (string) ($mapping['flowBehavior'] ?? 'FlowWhenChanged'),
            'flowType' => (string) ($mapping['flowType'] ?? 'Always'),
            'matchingPriority' => (int) ($mapping['matchingPriority'] ?? 0),
            'source' => [
                'expression' => $source['expression'] ?? null,
                'name' => $source['name'] ?? null,
                'parameters' => is_array($source['parameters'] ?? null) ? $source['parameters'] : [],
                'type' => $source['type'] ?? 'Attribute',
            ],
            'targetAttributeName' => (string) ($mapping['targetAttributeName'] ?? ''),
        ];

        // Drop nulls Graph sometimes rejects as "required missing".
        if ($out['defaultValue'] === null) {
            unset($out['defaultValue']);
        }
        if (($out['source']['name'] ?? null) === null) {
            unset($out['source']['name']);
        }
        if (($out['source']['expression'] ?? null) === null) {
            unset($out['source']['expression']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sanitizeSynchronizationSchemaForWrite(array $schema): array
    {
        unset($schema['@odata.context'], $schema['@odata.type'], $schema['id'], $schema['version']);

        if (isset($schema['synchronizationRules']) && is_array($schema['synchronizationRules'])) {
            foreach ($schema['synchronizationRules'] as $i => $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                unset($rule['@odata.type'], $rule['metadata']);
                if (isset($rule['objectMappings']) && is_array($rule['objectMappings'])) {
                    foreach ($rule['objectMappings'] as $j => $om) {
                        if (! is_array($om)) {
                            continue;
                        }
                        unset($om['@odata.type']);
                        if (isset($om['attributeMappings']) && is_array($om['attributeMappings'])) {
                            foreach ($om['attributeMappings'] as $k => $am) {
                                if (is_array($am)) {
                                    $om['attributeMappings'][$k] = $this->sanitizeAttributeMappingForWrite($am);
                                }
                            }
                        }
                        $rule['objectMappings'][$j] = $om;
                    }
                }
                $schema['synchronizationRules'][$i] = $rule;
            }
        }

        return $schema;
    }

    private function ensureScimSynchronizationJob(string $tenantId, string $servicePrincipalId): string
    {
        $this->discardStaleScimJobCache($tenantId, $servicePrincipalId);

        for ($listAttempt = 1; $listAttempt <= 6; $listAttempt++) {
            $existingId = $this->listScimSynchronizationJobId($tenantId, $servicePrincipalId);
            if ($existingId !== '') {
                return $existingId;
            }
            if ($listAttempt < 6) {
                usleep(500_000 * $listAttempt);
            }
        }

        $this->waitForScimSynchronizationTemplates($tenantId, $servicePrincipalId);

        if ($this->countScimSynchronizationTemplates($tenantId, $servicePrincipalId) === 0) {
            throw new RuntimeException(
                'Microsoft Graph returned zero SCIM provisioning templates for this Entra app. '
                .'Use **Retry Graph setup** on Edit Client - the portal deletes and recreates the SCIM app automatically, '
                .'then **Apply SCIM** with SuperOps Tenant URL + Secret Token.'
            );
        }

        $lastError = 'Could not create SCIM provisioning job.';
        foreach ($this->scimSynchronizationTemplateCandidates($tenantId, $servicePrincipalId) as $templateId) {
            try {
                $jobId = $this->attemptCreateScimSynchronizationJob($tenantId, $servicePrincipalId, $templateId);
                if ($jobId !== '') {
                    return $jobId;
                }
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError);
    }

    private function attemptCreateScimSynchronizationJob(
        string $tenantId,
        string $servicePrincipalId,
        string $templateId,
    ): string {
        $create = $this->graphPost(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs",
            ['templateId' => $templateId],
        );

        if ($create->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot create a SCIM provisioning job - Synchronization.ReadWrite.All missing or not consented.'
            );
        }

        if ($create->successful() && filled($create->json('id'))) {
            $id = (string) $create->json('id');
            if ($this->isUsableScimJobId($id)) {
                Cache::put('scim.job_id.'.$servicePrincipalId, $id, now()->addDays(7));

                return $id;
            }
        }

        $body = $create->body();
        $fromBody = $this->extractProvisioningJobIdFromBody($body);
        if ($fromBody !== '') {
            Log::info('SCIM job id recovered from create response body', [
                'tenant_id' => $tenantId,
                'template_id' => $templateId,
                'job_id' => $fromBody,
            ]);
            Cache::put('scim.job_id.'.$servicePrincipalId, $fromBody, now()->addDays(7));

            return $fromBody;
        }

        if (
            $create->status() === 400
            && (
                str_contains($body, 'ProvisioningTaskAlreadyExists')
                || str_contains($body, 'already exists')
            )
        ) {
            $existingId = $this->pollForListedScimJobId($tenantId, $servicePrincipalId);
            if ($existingId !== '') {
                return $existingId;
            }

            throw new RuntimeException(
                'A SCIM provisioning job already exists in Entra, but Microsoft Graph still did not return its id after ~2.5 min. '
                .'Use Retry SCIM export on Edit Client once more. Do not create a second job in the Azure UI.'
            );
        }

        if ($create->failed()) {
            throw new RuntimeException(
                'Microsoft Graph create SCIM job (template '.$templateId.') failed: '.$create->status().' '
                .$this->shortGraphErrorBody($body)
            );
        }

        return '';
    }

    private function pollForListedScimJobId(string $tenantId, string $servicePrincipalId): string
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            usleep(3_000_000);
            $existingId = $this->listScimSynchronizationJobId($tenantId, $servicePrincipalId);
            if ($existingId !== '') {
                Log::info('SCIM job id appeared after AlreadyExists lag', [
                    'tenant_id' => $tenantId,
                    'job_id' => $existingId,
                    'attempt' => $attempt,
                ]);
                Cache::put('scim.job_id.'.$servicePrincipalId, $existingId, now()->addDays(7));

                return $existingId;
            }

            $cached = Cache::get('scim.job_id.'.$servicePrincipalId);
            if (is_string($cached) && $this->isUsableScimJobId($cached) && $attempt >= 10) {
                Log::info('SCIM job id recovered from portal cache after AlreadyExists lag', [
                    'tenant_id' => $tenantId,
                    'job_id' => $cached,
                    'attempt' => $attempt,
                ]);

                return $cached;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function scimSynchronizationTemplateCandidates(string $tenantId, string $servicePrincipalId): array
    {
        $candidates = ['scim'];

        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/templates",
        );

        if ($response->successful()) {
            $templates = $response->json('value') ?? [];
            $scored = [];

            foreach (is_array($templates) ? $templates : [] as $template) {
                $id = (string) ($template['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $score = 0;
                $idLower = strtolower($id);
                if ($idLower === 'scim') {
                    $score += 100;
                }
                if (str_contains($idLower, 'scim')) {
                    $score += 50;
                }
                if (str_contains($idLower, 'outdelta') || str_contains($idLower, 'out')) {
                    $score += 10;
                }
                if (str_contains($idLower, 'custom')) {
                    $score += 5;
                }

                $scored[] = ['id' => $id, 'score' => $score];
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            foreach ($scored as $row) {
                $candidates[] = $row['id'];
            }
        }

        $candidates[] = 'customappsso';

        return array_values(array_unique($candidates));
    }

    private function waitForScimSynchronizationTemplates(
        string $tenantId,
        string $servicePrincipalId,
        int $maxAttempts = 12,
    ): void {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/templates",
            );

            if ($response->successful()) {
                $templates = $response->json('value') ?? [];
                if (is_array($templates) && $templates !== []) {
                    if ($attempt > 1) {
                        Log::info('SCIM synchronization templates appeared after wait', [
                            'tenant_id' => $tenantId,
                            'attempt' => $attempt,
                            'count' => count($templates),
                        ]);
                    }

                    return;
                }
            }

            if ($attempt < $maxAttempts) {
                usleep(3_000_000);
            }
        }
    }

    private function countScimSynchronizationTemplates(string $tenantId, string $servicePrincipalId): int
    {
        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/templates",
        );

        if (! $response->successful()) {
            return 0;
        }

        $templates = $response->json('value') ?? [];

        return is_array($templates) ? count($templates) : 0;
    }

    private function discardStaleScimJobCache(string $tenantId, string $servicePrincipalId): void
    {
        $cached = Cache::get('scim.job_id.'.$servicePrincipalId);
        if (! is_string($cached) || $cached === '') {
            return;
        }

        if ($this->listScimSynchronizationJobId($tenantId, $servicePrincipalId) !== '') {
            return;
        }

        if (! $this->isUsableScimJobId($cached)) {
            Cache::forget('scim.job_id.'.$servicePrincipalId);
            Log::info('Discarded stale SCIM job cache entry', [
                'tenant_id' => $tenantId,
                'service_principal_id' => $servicePrincipalId,
                'cached_job_id' => $cached,
            ]);
        }
    }

    private function isUsableScimJobId(string $jobId): bool
    {
        $jobId = strtolower(trim($jobId));

        // Healthy jobs look like scim.{hash}.{uuid} - two-segment ids are phantom Graph responses.
        return (bool) preg_match(
            '/^scim\.[0-9a-f]{8,}\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $jobId,
        );
    }

    /**
     * Try v1.0 then beta list - Graph sometimes lags one surface after job create.
     */
    private function listScimSynchronizationJobId(string $tenantId, string $servicePrincipalId): string
    {
        foreach (['v1.0', 'beta'] as $version) {
            $jobsResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/{$version}/servicePrincipals/{$servicePrincipalId}/synchronization/jobs",
            );

            if ($jobsResponse->status() === 403 && $version === 'v1.0') {
                throw new RuntimeException(
                    'Microsoft Graph cannot manage Entra provisioning - add Synchronization.ReadWrite.All '
                    .'on OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant, then retry.'
                );
            }

            if ($jobsResponse->failed()) {
                if ($version === 'beta') {
                    continue;
                }
                throw new RuntimeException(
                    'Microsoft Graph list provisioning jobs failed: '.$jobsResponse->status().' '
                    .$this->shortGraphErrorBody($jobsResponse->body())
                );
            }

            $jobs = $jobsResponse->json('value') ?? [];
            $id = $this->pickSynchronizationJobId(is_array($jobs) ? $jobs : []);
            if ($id !== '' && $this->isUsableScimJobId($id)) {
                Cache::put('scim.job_id.'.$servicePrincipalId, $id, now()->addDays(7));

                return $id;
            }
        }

        $cached = Cache::get('scim.job_id.'.$servicePrincipalId);
        if (is_string($cached) && $this->isUsableScimJobId($cached)) {
            return $cached;
        }

        return '';
    }

    /**
     * Pull a job id out of Graph error/success JSON when list is still empty.
     */
    private function extractProvisioningJobIdFromBody(string $body): string
    {
        if ($body === '') {
            return '';
        }

        if (preg_match('/"id"\s*:\s*"(scim\.[^"]+)"/i', $body, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function pickScimSynchronizationTemplateId(string $tenantId, string $servicePrincipalId): string
    {
        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/templates",
        );

        if ($response->successful()) {
            $templates = $response->json('value') ?? [];
            $best = null;
            $bestScore = PHP_INT_MIN;

            foreach (is_array($templates) ? $templates : [] as $template) {
                $id = (string) ($template['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $name = strtolower((string) ($template['metadata']['applicationId'] ?? $template['id'] ?? ''));
                $score = 0;

                if (str_contains(strtolower($id), 'scim') || str_contains($name, 'scim')) {
                    $score += 50;
                }
                if (str_contains(strtolower($id), 'custom')) {
                    $score += 20;
                }
                // Prefer outbound / user provisioning templates when labeled.
                if (str_contains(strtolower(json_encode($template)), 'user')) {
                    $score += 5;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $id;
                }
            }

            if ($best !== null) {
                return $best;
            }

            $first = is_array($templates) ? ($templates[0]['id'] ?? null) : null;
            if (filled($first)) {
                return (string) $first;
            }
        }

        // Non-gallery SCIM apps commonly use this built-in template id when the list is empty.
        return 'customappsso';
    }

    /**
     * @return array<string, string>
     */
    private function readScimSynchronizationSecrets(string $tenantId, string $servicePrincipalId): array
    {
        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/secrets",
        );

        if ($response->failed()) {
            return [];
        }

        $values = $response->json('value') ?? [];
        if (! is_array($values)) {
            return [];
        }

        $secrets = [];
        foreach ($values as $item) {
            if (! is_array($item) || ! isset($item['key'])) {
                continue;
            }
            $secrets[(string) $item['key']] = trim((string) ($item['value'] ?? ''));
        }

        return $secrets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchScimSynchronizationJobs(string $tenantId, string $servicePrincipalId): array
    {
        foreach (['v1.0', 'beta'] as $version) {
            $jobsResponse = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/{$version}/servicePrincipals/{$servicePrincipalId}/synchronization/jobs",
            );

            if ($jobsResponse->failed()) {
                continue;
            }

            $jobs = $jobsResponse->json('value') ?? [];

            if (is_array($jobs) && $jobs !== []) {
                return $jobs;
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     */
    private function extractSynchronizationJobState(array $jobs, string $jobId): string
    {
        foreach ($jobs as $job) {
            if ((string) ($job['id'] ?? '') !== $jobId) {
                continue;
            }

            return strtolower((string) (
                $job['status']['code']
                ?? $job['status']['state']
                ?? $job['schedule']['state']
                ?? ''
            ));
        }

        return '';
    }

    /**
     * @param  list<string>  $details
     * @param  list<string>  $warnings
     * @return array{
     *     ok: bool,
     *     servicePrincipalId: string,
     *     jobId: string,
     *     jobCount: int,
     *     jobState: string,
     *     hasProvisioningJob: bool,
     *     hasScimSecrets: bool,
     *     needsApplyScim: bool,
     *     needsRepair: bool,
     *     scimBaseAddress: string,
     *     details: list<string>,
     *     warnings: list<string>,
     *     error: string|null
     * }
     */
    private function scimHealthResult(
        bool $ok,
        string $servicePrincipalId,
        string $jobId,
        int $jobCount,
        string $jobState,
        bool $hasProvisioningJob,
        bool $hasScimSecrets,
        bool $needsApplyScim,
        bool $needsRepair,
        string $scimBaseAddress,
        array $details,
        array $warnings,
        ?string $error,
    ): array {
        return [
            'ok' => $ok,
            'servicePrincipalId' => $servicePrincipalId,
            'jobId' => $jobId,
            'jobCount' => $jobCount,
            'jobState' => $jobState,
            'hasProvisioningJob' => $hasProvisioningJob,
            'hasScimSecrets' => $hasScimSecrets,
            'needsApplyScim' => $needsApplyScim,
            'needsRepair' => $needsRepair,
            'scimBaseAddress' => $scimBaseAddress,
            'details' => $details,
            'warnings' => $warnings,
            'error' => $error,
        ];
    }

    private function putScimSynchronizationSecrets(
        string $tenantId,
        string $servicePrincipalId,
        string $scimTenantUrl,
        string $scimSecretToken,
    ): void {
        $response = $this->graphPut($tenantId, "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/secrets", [
            'value' => [
                ['key' => 'BaseAddress', 'value' => $scimTenantUrl],
                ['key' => 'SecretToken', 'value' => $scimSecretToken],
                ['key' => 'SyncNotificationSettings', 'value' => '{"Enabled":false,"DeleteThresholdEnabled":false}'],
                // Scope to assigned users/groups (matches Free + portal app assign / P1 group assign).
                ['key' => 'SyncAll', 'value' => 'false'],
            ],
        ]);

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot write SCIM secrets - Synchronization.ReadWrite.All missing or not consented in this customer tenant.'
            );
        }

        if ($response->failed() && $response->status() !== 204) {
            throw new RuntimeException(
                'Microsoft Graph write SCIM secrets failed: '.$response->status().' '.$response->body()
            );
        }
    }

    private function startScimSynchronizationJob(string $tenantId, string $servicePrincipalId, string $jobId): bool
    {
        $response = $this->graphPost(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/synchronization/jobs/{$jobId}/start",
        );

        // 204 = started; 400 already active is also OK
        if ($response->status() === 204 || $response->successful()) {
            return true;
        }

        if ($response->status() === 400 && (
            str_contains($response->body(), 'already')
            || str_contains(strtolower($response->body()), 'active')
        )) {
            return true;
        }

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot start SCIM provisioning - Synchronization.ReadWrite.All missing or not consented.'
            );
        }

        Log::warning('Microsoft Graph start SCIM job non-success', [
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    /**
     * Configure SAML on SuperOps Client SSO Entra app so technicians only paste Login URL + cert into SuperOps.
     * SuperOps Entity ID + ACS still come from SuperOps (no SuperOps API).
     *
     * @return array{
     *     loginUrl: string,
     *     certificateBase64: string,
     *     azureAdIdentifier: string,
     *     servicePrincipalId: string,
     *     applicationObjectId: string,
     *     details: list<string>,
     *     warnings: list<string>
     * }
     */
    public function applyClientSsoSamlConfiguration(
        string $tenantId,
        string $ssoAppClientId,
        string $entityId,
        string $consumerServiceUrl,
    ): array {
        $tenantId = strtolower(trim($tenantId));
        $ssoAppClientId = strtolower(trim($ssoAppClientId));
        $entityId = trim($entityId);
        $consumerServiceUrl = rtrim(trim($consumerServiceUrl), '/');
        $details = [];
        $warnings = [];

        if ($entityId === '' || $consumerServiceUrl === '') {
            throw new RuntimeException('SuperOps Entity ID and Consumer Service URL are required.');
        }

        $application = $this->waitForApplicationByAppId($tenantId, $ssoAppClientId);
        $applicationObjectId = $application['applicationObjectId'];
        $servicePrincipalId = $this->ensureServicePrincipalForAppId($tenantId, $ssoAppClientId);
        $details[] = 'Client SSO app registration and service principal ready';

        $modePatch = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}", [
            'preferredSingleSignOnMode' => 'saml',
        ]);

        if ($modePatch->failed() && $modePatch->status() !== 204) {
            throw new RuntimeException(
                'Microsoft Graph could not set SAML SSO mode: '.$modePatch->status().' '.$modePatch->body()
            );
        }
        $details[] = 'preferredSingleSignOnMode=saml';

        $appPatch = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/applications/{$applicationObjectId}", [
            'identifierUris' => [$entityId],
            'web' => [
                'redirectUris' => [$consumerServiceUrl],
            ],
        ]);

        if ($appPatch->failed() && $appPatch->status() !== 204) {
            throw new RuntimeException(
                'Microsoft Graph could not set Entity ID / Reply URL: '.$appPatch->status().' '.$appPatch->body()
                .' (Entity ID and ACS must match SuperOps Client SSO for this customer only.)'
            );
        }
        $details[] = 'Entity ID + Reply URL (ACS) set on application';

        $spPatch = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}", [
            'replyUrls' => [$consumerServiceUrl],
            'loginUrl' => null,
            'logoutUrl' => null,
        ]);

        if ($spPatch->failed() && $spPatch->status() !== 204) {
            $warnings[] = 'Service principal reply URL update soft-failed: '.$spPatch->status();
        }

        try {
            $this->ensureBasicSamlClaimsMappingPolicy($tenantId, $servicePrincipalId);
            $details[] = 'SAML claims email/firstname/lastname policy assigned (or already present)';
        } catch (Throwable $e) {
            // Claims are required for clean SuperOps login; main SAML wire still succeeded above.
            // Do not shrug this as "optional meh" - surface as warning with remediation after retries.
            $warnings[] = 'SAML claims policy NOT applied (after Graph retries): '.$e->getMessage();
            Log::warning('Client SSO SAML claims mapping failed after retries', [
                'tenant_id' => $tenantId,
                'service_principal_id' => $servicePrincipalId,
                'error' => $e->getMessage(),
            ]);
        }

        $certificateBase64 = $this->ensureTokenSigningCertificateBase64($tenantId, $servicePrincipalId);
        $details[] = 'Token signing certificate ready';

        $loginUrl = "https://login.microsoftonline.com/{$tenantId}/saml2";
        $azureAdIdentifier = "https://sts.windows.net/{$tenantId}/";

        return [
            'loginUrl' => $loginUrl,
            'certificateBase64' => $certificateBase64,
            'azureAdIdentifier' => $azureAdIdentifier,
            'servicePrincipalId' => $servicePrincipalId,
            'applicationObjectId' => $applicationObjectId,
            'details' => $details,
            'warnings' => $warnings,
        ];
    }

    private function ensureTokenSigningCertificateBase64(string $tenantId, string $servicePrincipalId): string
    {
        $existing = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}",
            ['$select' => 'id,keyCredentials'],
        );

        if ($existing->successful()) {
            foreach ($existing->json('keyCredentials') ?? [] as $credential) {
                $usage = strtolower((string) ($credential['usage'] ?? ''));
                $type = strtolower((string) ($credential['type'] ?? ''));
                $key = (string) ($credential['key'] ?? '');

                if ($key !== '' && str_contains($usage, 'sign') && str_contains($type, 'x509')) {
                    return $this->normalizeCertificateBody($key);
                }
            }

            // Any AsymmetricX509Cert with key material.
            foreach ($existing->json('keyCredentials') ?? [] as $credential) {
                $key = (string) ($credential['key'] ?? '');
                $type = strtolower((string) ($credential['type'] ?? ''));
                if ($key !== '' && str_contains($type, 'x509')) {
                    return $this->normalizeCertificateBody($key);
                }
            }
        }

        $create = $this->graphPost(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/addTokenSigningCertificate",
            [
                'displayName' => 'CN=OnIT SuperOps Client SSO',
                'endDateTime' => now()->addYears(3)->toIso8601String(),
            ],
        );

        if ($create->failed() || empty($create->json('key'))) {
            throw new RuntimeException(
                'Microsoft Graph addTokenSigningCertificate failed: '.$create->status().' '.$create->body()
            );
        }

        return $this->normalizeCertificateBody((string) $create->json('key'));
    }

    private function normalizeCertificateBody(string $raw): string
    {
        $raw = trim($raw);

        if (str_contains($raw, 'BEGIN CERTIFICATE')) {
            $raw = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $raw) ?? $raw;
        }

        // Graph may return raw base64 of DER; SuperOps wants the same single-line body (no markers).
        return preg_replace('/\s+/', '', $raw) ?? $raw;
    }

    private function ensureBasicSamlClaimsMappingPolicy(string $tenantId, string $servicePrincipalId): void
    {
        $policyName = 'OnIT SuperOps Client SSO claims';
        $definitionJson = json_encode([
            'ClaimsMappingPolicy' => [
                'Version' => 1,
                'IncludeBasicClaimSet' => 'true',
                'ClaimsSchema' => [
                    [
                        'Source' => 'user',
                        'ID' => 'mail',
                        'SamlClaimType' => 'email',
                    ],
                    [
                        'Source' => 'user',
                        'ID' => 'givenname',
                        'SamlClaimType' => 'firstname',
                    ],
                    [
                        'Source' => 'user',
                        'ID' => 'surname',
                        'SamlClaimType' => 'lastname',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        // SP must be readable in this tenant before policy $ref assign (404 Directory_ObjectNotFound otherwise).
        $this->waitUntilServicePrincipalReadable($tenantId, $servicePrincipalId);

        if ($this->servicePrincipalHasClaimsPolicyNamed($tenantId, $servicePrincipalId, $policyName)) {
            return;
        }

        $policyId = $this->resolveOrCreateClaimsMappingPolicyId($tenantId, $policyName, $definitionJson);
        $this->waitUntilClaimsMappingPolicyReadable($tenantId, $policyId);

        $lastStatus = null;
        $lastBody = '';
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            if ($this->servicePrincipalHasClaimsPolicyNamed($tenantId, $servicePrincipalId, $policyName)) {
                return;
            }

            // Re-resolve SP id by current object (handles rare SP replace mid-bootstrap).
            $spCheck = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}",
                ['$select' => 'id'],
            );
            if ($spCheck->status() === 404) {
                throw new RuntimeException(
                    'Service principal disappeared while assigning SAML claims (HTTP 404). '
                    .'Re-run Wire SuperOps into Microsoft Entra on Edit Client; do not recreate SuperOps Client SSO in SuperOps unless wire fails after 2 minutes.'
                );
            }

            $assign = $this->graphPost(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/claimsMappingPolicies/\$ref",
                [
                    '@odata.id' => "https://graph.microsoft.com/v1.0/policies/claimsMappingPolicies/{$policyId}",
                ],
            );

            $lastStatus = $assign->status();
            $lastBody = $assign->body();

            if ($assign->successful() || $assign->status() === 204
                || str_contains($lastBody, 'already')) {
                usleep(400_000);
                if ($this->servicePrincipalHasClaimsPolicyNamed($tenantId, $servicePrincipalId, $policyName)) {
                    return;
                }
            }

            // 400 often means already assigned under another name or conflict - re-check list.
            if ($assign->status() === 400) {
                usleep(500_000);
                if ($this->servicePrincipalHasClaimsPolicyNamed($tenantId, $servicePrincipalId, $policyName)) {
                    return;
                }
            }

            // 404 / 429 / 5xx: directory replication or throttle - wait and retry.
            if (in_array($assign->status(), [404, 408, 409, 429, 500, 502, 503, 504], true)
                || $assign->failed()) {
                usleep(min(2_000_000, 250_000 * $attempt * $attempt));
                // Policy may have been created under another attempt / concurrent wire.
                try {
                    $policyId = $this->resolveOrCreateClaimsMappingPolicyId($tenantId, $policyName, $definitionJson);
                    $this->waitUntilClaimsMappingPolicyReadable($tenantId, $policyId, maxAttempts: 4);
                } catch (Throwable) {
                    // keep last error below
                }

                continue;
            }

            break;
        }

        if ($this->servicePrincipalHasClaimsPolicyNamed($tenantId, $servicePrincipalId, $policyName)) {
            return;
        }

        throw new RuntimeException(
            'Assign claims mapping policy failed after retries'
            .($lastStatus !== null ? " (last HTTP {$lastStatus})" : '')
            .($lastBody !== '' ? ': '.$this->shortGraphErrorBody($lastBody) : '.')
            .' Policy Id '.$policyId.' → service principal '.$servicePrincipalId.'. '
            .'This is a real Graph failure (policy assign), not a soft success. '
            .'Fix: (1) Re-run Wire after ~2 minutes; (2) confirm Policy.ReadWrite.ApplicationConfiguration consented in this customer; '
            .'(3) or set Attributes & Claims manually on the Client SSO enterprise app: email=user.mail, firstname=user.givenname, lastname=user.surname.'
        );
    }

    private function servicePrincipalHasClaimsPolicyNamed(
        string $tenantId,
        string $servicePrincipalId,
        string $policyName,
    ): bool {
        $existingAssigned = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}/claimsMappingPolicies",
        );

        if (! $existingAssigned->successful()) {
            return false;
        }

        foreach ($existingAssigned->json('value') ?? [] as $policy) {
            if (strcasecmp((string) ($policy['displayName'] ?? ''), $policyName) === 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveOrCreateClaimsMappingPolicyId(
        string $tenantId,
        string $policyName,
        string $definitionJson,
    ): string {
        $listed = $this->findClaimsMappingPolicyIdByName($tenantId, $policyName);
        if ($listed !== null) {
            return $listed;
        }

        $create = $this->graphPost($tenantId, 'https://graph.microsoft.com/v1.0/policies/claimsMappingPolicies', [
            'definition' => [$definitionJson],
            'displayName' => $policyName,
            'isOrganizationDefault' => false,
        ]);

        if ($create->status() === 403) {
            throw new RuntimeException(
                'Policy.ReadWrite.ApplicationConfiguration missing - add it to OnIT Portal for Portals (Application permission), Grant admin consent in the On IT tenant, re-consent the **customer** tenant (Connect / Accept again), then Retry Wire; or set Attributes & Claims manually in Azure for this Client SSO app.'
            );
        }

        $policyId = (string) ($create->json('id') ?? '');
        if ($policyId !== '') {
            return $policyId;
        }

        // Create may race / conflict if another Wire request just created it.
        $again = $this->findClaimsMappingPolicyIdByName($tenantId, $policyName);
        if ($again !== null) {
            return $again;
        }

        throw new RuntimeException(
            'Create claims mapping policy failed: '.$create->status().' '.$this->shortGraphErrorBody($create->body())
        );
    }

    private function findClaimsMappingPolicyIdByName(string $tenantId, string $policyName): ?string
    {
        $list = $this->graphGet($tenantId, 'https://graph.microsoft.com/v1.0/policies/claimsMappingPolicies', [
            '$filter' => "displayName eq '".str_replace("'", "''", $policyName)."'",
        ]);

        if (! $list->successful()) {
            return null;
        }

        $id = (string) (($list->json('value')[0]['id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    private function waitUntilClaimsMappingPolicyReadable(
        string $tenantId,
        string $policyId,
        int $maxAttempts = 10,
    ): void {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $get = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/policies/claimsMappingPolicies/{$policyId}",
                ['$select' => 'id,displayName'],
            );
            if ($get->successful() && filled($get->json('id'))) {
                return;
            }
            usleep(min(1_500_000, 200_000 * $attempt));
        }

        throw new RuntimeException(
            "Claims mapping policy {$policyId} not readable in Graph after create (directory not ready). Re-run Wire in 1-2 minutes."
        );
    }

    private function waitUntilServicePrincipalReadable(string $tenantId, string $servicePrincipalId): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $get = $this->graphGet(
                $tenantId,
                "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}",
                ['$select' => 'id,appId,displayName'],
            );
            if ($get->successful() && filled($get->json('id'))) {
                return;
            }
            usleep(min(1_500_000, 200_000 * $attempt));
        }

        throw new RuntimeException(
            "Service principal {$servicePrincipalId} not readable before claims assign. Re-run Wire SuperOps into Microsoft Entra after Connect/bootstrap settles."
        );
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     */
    private function pickSynchronizationJobId(array $jobs): string
    {
        $preferredStates = ['active', 'paused', 'quarantine', 'notstarted', 'entryimport', 'entriesexport'];

        foreach ($jobs as $job) {
            $state = strtolower((string) (
                $job['status']['code']
                ?? $job['status']['state']
                ?? $job['schedule']['state']
                ?? ''
            ));

            if (in_array($state, $preferredStates, true) && ! empty($job['id'])) {
                return (string) $job['id'];
            }
        }

        foreach ($jobs as $job) {
            if (! empty($job['id'])) {
                return (string) $job['id'];
            }
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function pickUserSynchronizationRuleId(array $rules): ?string
    {
        $bestRuleId = null;
        $bestScore = PHP_INT_MIN;

        foreach ($rules as $rule) {
            $ruleId = trim((string) ($rule['id'] ?? ''));

            if ($ruleId === '') {
                continue;
            }

            foreach ($rule['objectMappings'] ?? [] as $mapping) {
                if (strcasecmp((string) ($mapping['sourceObjectName'] ?? ''), 'User') !== 0) {
                    continue;
                }

                $sourceDirectory = strtolower((string) ($rule['sourceDirectoryName'] ?? ''));
                $score = 0;

                if (str_contains($sourceDirectory, 'azure') || str_contains($sourceDirectory, 'entra')) {
                    $score += 10;
                }

                if (($mapping['enabled'] ?? true) !== false) {
                    $score += 5;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestRuleId = $ruleId;
                }
            }
        }

        return $bestRuleId;
    }

    public function setSuperOpsNameExtensionAttribute(string $tenantId, string $userId, string $hint): void
    {
        $attributeNumber = (int) config('services.entra_sync.superops_name_extension_attribute', 1);

        if ($attributeNumber < 1 || $attributeNumber > 15) {
            throw new RuntimeException('ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE must be between 1 and 15.');
        }

        $key = 'extensionAttribute'.$attributeNumber;

        $response = $this->graphPatch($tenantId, "https://graph.microsoft.com/v1.0/users/{$userId}", [
            'onPremisesExtensionAttributes' => [
                $key => $hint,
            ],
        ]);

        if ($response->status() === 204) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph update SuperOps SCIM name failed: '.$response->status().' '.$response->body()
        );
    }

    private function request(string $tenantId, bool $refreshToken = false): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken($tenantId, $refreshToken))
            ->timeout(30);
    }

    public function clearAccessTokenCache(string $tenantId): void
    {
        Cache::forget('entra_graph_token.'.$tenantId);
    }

    /**
     * After admin consent, app-only Graph often 401/403 for seconds-minutes
     * (IdentityNotFound / permissions not live). Poll until organization reads
     * or attempts are exhausted so Connect bootstrap can continue.
     *
     * @return array{ready: bool, attempts: int, last_error: ?string}
     */
    public function waitUntilAppOnlyGraphReady(string $tenantId, int $maxAttempts = 12): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->clearAccessTokenCache($tenantId);

            try {
                $response = $this->graphGet(
                    $tenantId,
                    'https://graph.microsoft.com/v1.0/organization',
                    ['$select' => 'id', '$top' => 1],
                );

                if ($response->successful()) {
                    return [
                        'ready' => true,
                        'attempts' => $attempt,
                        'last_error' => null,
                    ];
                }

                $lastError = 'HTTP '.$response->status().' '.$this->shortGraphErrorBody($response->body());

                if (! $this->isConsentPropagationGraphError($response) && ! in_array($response->status(), [429, 502, 503, 504], true)) {
                    return [
                        'ready' => false,
                        'attempts' => $attempt,
                        'last_error' => $lastError,
                    ];
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt < $maxAttempts) {
                usleep(min(2_500_000, 600_000 * $attempt));
            }
        }

        return [
            'ready' => false,
            'attempts' => $maxAttempts,
            'last_error' => $lastError,
        ];
    }

    /**
     * Retry Graph work that often fails in the seconds after Accept.
     *
     * @template T
     * @param  callable(): T  $operation
     * @return T
     */
    public function retryAfterConsentPropagation(string $tenantId, callable $operation, int $maxAttempts = 6): mixed
    {
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $last = $e;
                if ($attempt >= $maxAttempts || ! $this->isConsentPropagationMessage($e->getMessage())) {
                    throw $e;
                }
                $this->clearAccessTokenCache($tenantId);
                usleep(min(2_500_000, 700_000 * $attempt));
            }
        }

        throw $last ?? new RuntimeException('Graph operation failed after consent wait.');
    }

    public function isConsentPropagationMessage(string $message): bool
    {
        $needle = strtolower($message);

        return str_contains($needle, 'identitynotfound')
            || str_contains($needle, 'authorization_identitynotfound')
            || str_contains($needle, 'authorization_requestdenied')
            || str_contains($needle, 'insufficient privileges')
            || str_contains($needle, 'identity of the calling application')
            || str_contains($needle, 'not readable yet')
            || str_contains($needle, 'could not be established');
    }

    private function isConsentPropagationGraphError(Response $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return $this->isConsentPropagationMessage($response->body())
                || $response->status() === 401;
        }

        return false;
    }

    private function shortGraphErrorBody(string $body): string
    {
        $trimmed = trim($body);

        return strlen($trimmed) > 180 ? substr($trimmed, 0, 180).'…' : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function graphGetWithTransientRetry(string $tenantId, string $url, array $query = [], int $maxAttempts = 3): Response
    {
        $response = $this->graphGet($tenantId, $url, $query);

        for ($attempt = 2; $attempt <= $maxAttempts && $this->shouldRetryTransientGraphError($response); $attempt++) {
            usleep(500_000 * ($attempt - 1));
            $response = $this->graphGet($tenantId, $url, $query);
        }

        return $response;
    }

    private function shouldRetryTransientGraphError(Response $response): bool
    {
        return in_array($response->status(), [429, 502, 503, 504], true)
            || $this->isConsentPropagationGraphError($response);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function graphGet(string $tenantId, string $url, array $query = []): Response
    {
        $response = $this->request($tenantId)->get($url, $query);

        if ($this->shouldRefreshTokenOnResponse($response)) {
            $response = $this->request($tenantId, refreshToken: true)->get($url, $query);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function graphPut(string $tenantId, string $url, array $data = []): \Illuminate\Http\Client\Response
    {
        $response = $this->request($tenantId)->put($url, $data);

        if ($this->shouldRefreshTokenOnResponse($response)) {
            $response = $this->request($tenantId, refreshToken: true)->put($url, $data);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function graphPost(string $tenantId, string $url, array $data = []): \Illuminate\Http\Client\Response
    {
        $response = $this->request($tenantId)->post($url, $data);

        if ($this->shouldRefreshTokenOnResponse($response)) {
            $response = $this->request($tenantId, refreshToken: true)->post($url, $data);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function graphPatch(string $tenantId, string $url, array $data = []): \Illuminate\Http\Client\Response
    {
        $response = $this->request($tenantId)->patch($url, $data);

        if ($this->shouldRefreshTokenOnResponse($response)) {
            $response = $this->request($tenantId, refreshToken: true)->patch($url, $data);
        }

        return $response;
    }

    private function graphDelete(string $tenantId, string $url): \Illuminate\Http\Client\Response
    {
        $response = $this->request($tenantId)->delete($url);

        if ($this->shouldRefreshTokenOnResponse($response)) {
            $response = $this->request($tenantId, refreshToken: true)->delete($url);
        }

        return $response;
    }

    private function shouldRefreshTokenOnResponse(\Illuminate\Http\Client\Response $response): bool
    {
        return $response->status() === 403
            && str_contains($response->body(), 'Authorization_RequestDenied');
    }

    private function accessToken(string $tenantId, bool $refresh = false): string
    {
        if ($refresh) {
            Cache::forget('entra_graph_token.'.$tenantId);
        }

        return Cache::remember(
            'entra_graph_token.'.$tenantId,
            now()->addMinutes(50),
            function () use ($tenantId) {
                $response = Http::asForm()->post(
                    "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                    [
                        'client_id' => config('services.entra_sync.client_id'),
                        'client_secret' => config('services.entra_sync.client_secret'),
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ]
                );

                if ($response->failed()) {
                    throw new RuntimeException(
                        'Failed to obtain Graph token for tenant '.$tenantId.': '.$response->body()
                    );
                }

                return $response->json('access_token');
            }
        );
    }

    /**
     * Latest Microsoft Secure Score percentage (0-100), if Graph grants SecurityEvents.Read.All.
     * Returns null when the call is forbidden or unconfigured - never throws for 403.
     *
     * @return array{score: ?float, max: ?float, percentage: ?float, available: bool, reason: ?string}
     */
    public function getSecureScoreSummary(string $tenantId): array
    {
        try {
            $response = $this->request($tenantId)
                ->get('https://graph.microsoft.com/v1.0/security/secureScores', [
                    '$top' => 1,
                    '$orderby' => 'createdDateTime desc',
                    '$select' => 'currentScore,maxScore,createdDateTime',
                ]);
        } catch (Throwable $e) {
            return [
                'score' => null,
                'max' => null,
                'percentage' => null,
                'available' => false,
                'reason' => 'secure_score_request_failed',
            ];
        }

        if ($response->status() === 403 || $response->status() === 401) {
            return [
                'score' => null,
                'max' => null,
                'percentage' => null,
                'available' => false,
                'reason' => 'secure_score_permission_missing',
            ];
        }

        if ($response->failed()) {
            return [
                'score' => null,
                'max' => null,
                'percentage' => null,
                'available' => false,
                'reason' => 'secure_score_unavailable',
            ];
        }

        $value = $response->json('value') ?? [];
        $row = (is_array($value) && isset($value[0]) && is_array($value[0])) ? $value[0] : null;
        if ($row === null) {
            return [
                'score' => null,
                'max' => null,
                'percentage' => null,
                'available' => false,
                'reason' => 'secure_score_empty',
            ];
        }

        $current = isset($row['currentScore']) ? (float) $row['currentScore'] : null;
        $max = isset($row['maxScore']) ? (float) $row['maxScore'] : null;
        $pct = ($current !== null && $max !== null && $max > 0)
            ? round(($current / $max) * 100, 1)
            : null;

        return [
            'score' => $current,
            'max' => $max,
            'percentage' => $pct,
            'available' => $pct !== null,
            'reason' => $pct === null ? 'secure_score_empty' : null,
        ];
    }

    /**
     * MFA / passwordless registration coverage from auth methods reports.
     * Needs Reports.Read.All (or ReportsReader) + AuditLog.Read.All depending on tenant.
     *
     * @return array{registered_pct: ?float, capable_pct: ?float, total_users: ?int, available: bool, reason: ?string}
     */
    public function getMfaRegistrationSummary(string $tenantId): array
    {
        $registered = 0;
        $capable = 0;
        $total = 0;
        $url = 'https://graph.microsoft.com/v1.0/reports/authenticationMethods/userRegistrationDetails';
        $query = [
            '$select' => 'id,isMfaRegistered,isMfaCapable,userType',
            '$top' => 999,
        ];

        try {
            do {
                $response = $this->request($tenantId)->get($url, $query);
                $query = []; // nextLink already absolute

                if ($response->status() === 403 || $response->status() === 401) {
                    return [
                        'registered_pct' => null,
                        'capable_pct' => null,
                        'total_users' => null,
                        'available' => false,
                        'reason' => 'mfa_permission_missing',
                    ];
                }

                if ($response->failed()) {
                    return [
                        'registered_pct' => null,
                        'capable_pct' => null,
                        'total_users' => null,
                        'available' => false,
                        'reason' => 'mfa_unavailable',
                    ];
                }

                foreach ($response->json('value') ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    // Member users only - skip guests when Graph marks them.
                    $type = strtolower((string) ($row['userType'] ?? 'member'));
                    if ($type !== '' && $type !== 'member') {
                        continue;
                    }
                    $total++;
                    if (! empty($row['isMfaRegistered'])) {
                        $registered++;
                    }
                    if (! empty($row['isMfaCapable'])) {
                        $capable++;
                    }
                }

                $next = $response->json('@odata.nextLink');
                $url = is_string($next) && $next !== '' ? $next : '';
            } while ($url !== '');
        } catch (Throwable) {
            return [
                'registered_pct' => null,
                'capable_pct' => null,
                'total_users' => null,
                'available' => false,
                'reason' => 'mfa_request_failed',
            ];
        }

        if ($total === 0) {
            return [
                'registered_pct' => null,
                'capable_pct' => null,
                'total_users' => 0,
                'available' => false,
                'reason' => 'mfa_empty',
            ];
        }

        return [
            'registered_pct' => round(($registered / $total) * 100, 1),
            'capable_pct' => round(($capable / $total) * 100, 1),
            'total_users' => $total,
            'available' => true,
            'reason' => null,
        ];
    }
}
