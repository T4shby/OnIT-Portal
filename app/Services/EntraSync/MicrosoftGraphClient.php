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
            '$select' => 'id,mail,userPrincipalName,displayName,givenName,surname,accountEnabled,assignedLicenses,onPremisesExtensionAttributes',
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
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    /**
     * Entra directory tier used by portal Free vs P1 assignment path (not M365 user SKUs alone).
     * AAD_PREMIUM / AAD_PREMIUM_P2 / EMS premium suites imply group assignment is available.
     */
    public function detectEntraDirectoryLicenseTier(string $tenantId): string
    {
        $partNumbers = array_map(
            static fn (string $sku): string => strtoupper($sku),
            array_values($this->listSubscribedSkuPartNumbersById($tenantId)),
        );

        foreach ($partNumbers as $sku) {
            if (
                str_contains($sku, 'AAD_PREMIUM')
                || $sku === 'EMS'
                || $sku === 'EMSPREMIUM'
                || $sku === 'ENTERPRISEPREMIUM'
                || $sku === 'SPE_E5'
                || $sku === 'SPE_E3'
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
            'description' => $description ?? 'Managed by On IT Portal — membership filled by sync.',
            'mailEnabled' => false,
            'mailNickname' => $mailNickname,
            'securityEnabled' => true,
        ]);

        if ($response->status() === 201 && filled($response->json('id'))) {
            return (string) $response->json('id');
        }

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot create security groups — add Group.ReadWrite.All (or Group.Create) '
                .'to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant, then retry Connect.'
            );
        }

        throw new RuntimeException(
            'Microsoft Graph create group failed: '.$response->status().' '.$response->body()
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
                'Microsoft Graph cannot list applications — Application.Read.All missing or not consented for this tenant.'
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
     * Prefers POST /applications (with User app role) over template instantiate — more reliable
     * against directory replication 404s when immediately reading/patching the new object.
     *
     * @return array{appId: string, applicationObjectId: string, servicePrincipalId: string}
     */
    public function createNonGalleryApplication(string $tenantId, string $displayName): array
    {
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
                'Microsoft Graph cannot create enterprise apps — add Application.ReadWrite.All '
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

        $servicePrincipalId = $this->ensureServicePrincipalForAppId($tenantId, $appId);
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
        Response $failedDirectCreate,
    ): array {
        // Gallery template ID for "Non-gallery" / custom LOB apps.
        $templateId = '8adf8e6e-67b2-4cf2-a259-e3dc5476c621';
        $response = $this->graphPost(
            $tenantId,
            "https://graph.microsoft.com/v1.0/applicationTemplates/{$templateId}/instantiate",
            ['displayName' => $displayName],
        );

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot create enterprise apps — add Application.ReadWrite.All '
                .'to OnIT Portal for Portals in the On IT tenant, re-consent in the customer tenant, then retry Connect.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph create non-gallery app failed: direct='
                .$failedDirectCreate->status().' '.$failedDirectCreate->body()
                .'; template='.$response->status().' '.$response->body()
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

        if ($servicePrincipalId === '') {
            $servicePrincipalId = $this->ensureServicePrincipalForAppId($tenantId, $appId);
        }

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

        // Concurrent create or eventual consistency — resolve again.
        return $this->resolveEnterpriseServicePrincipalId($tenantId, $appId);
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
    public function ensureNamedEnterpriseApplication(string $tenantId, string $displayName): array
    {
        $existing = $this->findApplicationByDisplayName($tenantId, $displayName);

        if ($existing !== null) {
            // Re-resolve object id in case a stale list entry races (common after partial bootstrap).
            $resolved = $this->waitForApplicationByAppId(
                $tenantId,
                $existing['appId'],
                $existing['applicationObjectId'],
            );

            return [
                'appId' => $resolved['appId'],
                'applicationObjectId' => $resolved['applicationObjectId'],
                'servicePrincipalId' => $existing['servicePrincipalId'] !== ''
                    ? $existing['servicePrincipalId']
                    : $this->ensureServicePrincipalForAppId($tenantId, $resolved['appId']),
            ];
        }

        return $this->createNonGalleryApplication($tenantId, $displayName);
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
                'Microsoft Graph cannot update app roles — add Application.ReadWrite.All, re-consent, retry Connect.'
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
    ): void {
        $response = $this->graphPost($tenantId, "https://graph.microsoft.com/v1.0/groups/{$groupId}/appRoleAssignments", [
            'principalId' => $groupId,
            'resourceId' => $servicePrincipalId,
            'appRoleId' => $appRoleId,
        ]);

        if ($response->status() === 201) {
            return;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'already exists')) {
            return;
        }

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Microsoft Graph cannot assign group to app — AppRoleAssignment.ReadWrite.All missing or not consented.'
            );
        }

        throw new RuntimeException(
            'Microsoft Graph assign group to enterprise app failed: '.$response->status().' '.$response->body()
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
     * @deprecated Use listSyncEligibleUsers() — group scope retained for SCIM reference only.
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
                'Microsoft Graph cannot resolve SuperOps app by Application (client) ID — Application.Read.All is missing or not consented. '
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
                        .'Paste the SuperOps Application (client) ID from App registrations → Overview — not the Object ID on that page. '
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

    public function resolveAssignableAppRoleId(string $tenantId, string $servicePrincipalId): string
    {
        $response = $this->graphGet(
            $tenantId,
            "https://graph.microsoft.com/v1.0/servicePrincipals/{$servicePrincipalId}",
            ['$select' => 'appRoles,displayName'],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph could not read SuperOps app roles: '.$response->status().' '.$response->body()
            );
        }

        $appRoles = $response->json('appRoles') ?? [];
        $selectedRoleId = $this->pickBestAssignableAppRoleId($appRoles);

        if ($selectedRoleId !== null) {
            return $selectedRoleId;
        }

        throw new RuntimeException(
            'SuperOps enterprise app has no assignable app role. One-time fix in customer Entra: '
            .'App registrations → your SuperOps app → App roles → Create app role → Display name User → '
            .'Value User → Allowed member types Users/Groups → Enable → Save. Remove duplicate roles with blank Value. Then Sync now.'
        );
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
                'Microsoft Graph assign user to enterprise app failed: 400 — no matching app role on the SuperOps app. '
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
                    'Microsoft Graph cannot read SuperOps provisioning jobs — Synchronization.ReadWrite.All is missing or not consented. '
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
     * @param  list<array<string, mixed>>  $jobs
     */
    private function pickSynchronizationJobId(array $jobs): string
    {
        foreach ($jobs as $job) {
            $state = strtolower((string) ($job['status']['state'] ?? ''));

            if (in_array($state, ['active', 'paused', 'quarantine'], true) && ! empty($job['id'])) {
                return (string) $job['id'];
            }
        }

        return (string) ($jobs[0]['id'] ?? '');
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
        return in_array($response->status(), [429, 502, 503, 504], true);
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
}
