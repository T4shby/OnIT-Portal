<?php

namespace Tests\Unit;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncDisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraGroupSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra_sync.enabled' => true,
            'services.entra_sync.client_id' => 'test-client-id',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);
    }

    public function test_sync_creates_licensed_users_with_formatted_display_name(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                'mail' => 'bob@acme.com',
                'userPrincipalName' => 'bob@acme.com',
                'displayName' => 'Bob Jones',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(2, $result->created);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.com',
            'name' => 'Jane Smith',
            'client_id' => $client->id,
            'entra_identity_type' => EntraIdentityType::User->value,
            'portal_login_enabled' => true,
            'provisioned_by' => UserProvisionSource::EntraSync->value,
            'is_active' => true,
        ]);
    }

    public function test_sync_creates_shared_mailbox_without_portal_login(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'dddddddd-dddd-dddd-dddd-dddddddddddd' => [
                'mail' => 'accounts@acme.com',
                'userPrincipalName' => 'accounts@acme.com',
                'displayName' => 'Accounts',
                'accountEnabled' => false,
                'licensed' => false,
                'mailboxPurpose' => 'shared',
            ],
        ]);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'accounts@acme.com',
            'name' => 'Accounts',
            'entra_identity_type' => EntraIdentityType::SharedMailbox->value,
            'portal_login_enabled' => false,
            'is_active' => true,
        ]);
    }

    public function test_sync_skips_unlicensed_users_that_are_not_shared_mailboxes(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee' => [
                'mail' => 'service@acme.com',
                'userPrincipalName' => 'service@acme.com',
                'displayName' => 'Service Account',
                'accountEnabled' => true,
                'licensed' => false,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('users', ['email' => 'service@acme.com']);
    }

    public function test_sync_deactivates_users_removed_from_scope(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'gone@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::EntraSync,
            'entra_object_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'is_active' => true,
            'portal_login_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, []);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->deactivated);
        $this->assertDatabaseHas('users', [
            'email' => 'gone@acme.com',
            'is_active' => false,
            'portal_login_enabled' => false,
        ]);
    }

    public function test_sync_deactivates_disabled_entra_accounts(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'disabled@acme.com',
                'userPrincipalName' => 'disabled@acme.com',
                'displayName' => 'Disabled User',
                'accountEnabled' => false,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'disabled@acme.com',
            'name' => 'Disabled User',
            'is_active' => false,
            'portal_login_enabled' => false,
        ]);
    }

    public function test_manual_users_are_not_deactivated_by_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'manual@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::Manual,
            'is_active' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, []);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'manual@acme.com',
            'is_active' => true,
        ]);
    }

    public function test_sync_maintains_superops_group_membership(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                'mail' => 'bob@acme.com',
                'userPrincipalName' => 'bob@acme.com',
                'displayName' => 'Bob Jones',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ], $groupId, [
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'cccccccc-cccc-cccc-cccc-cccccccccccc',
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->groupMembersAdded);
        $this->assertSame(1, $result->groupMembersRemoved);
        $this->assertSame(2, $result->created);
    }

    public function test_group_membership_sync_skipped_when_disabled(): void
    {
        config(['services.entra_sync.maintain_superops_group' => false]);

        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ], $groupId, []);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(0, $result->groupMembersAdded);
        $this->assertSame(0, $result->groupMembersRemoved);
    }

    public function test_sync_assigns_users_to_superops_enterprise_app_on_entra_id_free(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';
        $servicePrincipalId = '33333333-3333-3333-3333-333333333333';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_superops_app_id' => $servicePrincipalId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph(
            $tenantId,
            [
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                    'mail' => 'jane@acme.com',
                    'userPrincipalName' => 'jane@acme.com',
                    'displayName' => 'Jane Smith',
                    'accountEnabled' => true,
                    'licensed' => true,
                    'mailboxPurpose' => 'user',
                ],
                'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                    'mail' => 'bob@acme.com',
                    'userPrincipalName' => 'bob@acme.com',
                    'displayName' => 'Bob Jones',
                    'accountEnabled' => true,
                    'licensed' => true,
                    'mailboxPurpose' => 'user',
                ],
            ],
            $groupId,
            [],
            $servicePrincipalId,
            ['cccccccc-cccc-cccc-cccc-cccccccccccc'],
        );

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(2, $result->superOpsAppUsersAssigned);
        $this->assertSame(1, $result->superOpsAppUsersRemoved);
        $this->assertSame(2, $result->created);
    }

    public function test_shared_mailboxes_are_assigned_to_superops_enterprise_app(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $servicePrincipalId = '33333333-3333-3333-3333-333333333333';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_superops_app_id' => $servicePrincipalId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph(
            $tenantId,
            [
                'dddddddd-dddd-dddd-dddd-dddddddddddd' => [
                    'mail' => 'accounts@acme.com',
                    'userPrincipalName' => 'accounts@acme.com',
                    'displayName' => 'Accounts',
                    'accountEnabled' => false,
                    'licensed' => false,
                    'mailboxPurpose' => 'shared',
                ],
            ],
            servicePrincipalId: $servicePrincipalId,
        );

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->superOpsAppUsersAssigned);
        $this->assertSame(0, $result->superOpsAppUsersRemoved);
    }

    public function test_sync_resolves_superops_application_client_id_to_enterprise_app(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $applicationClientId = '8c46a344-a010-4c78-99b9-df8b9caaba2f';
        $servicePrincipalId = '33333333-3333-3333-3333-333333333333';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_superops_app_id' => $applicationClientId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph(
            $tenantId,
            [
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                    'mail' => 'jane@acme.com',
                    'userPrincipalName' => 'jane@acme.com',
                    'displayName' => 'Jane Smith',
                    'accountEnabled' => true,
                    'licensed' => true,
                    'mailboxPurpose' => 'user',
                ],
            ],
            servicePrincipalId: $servicePrincipalId,
            applicationClientId: $applicationClientId,
        );

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->superOpsAppUsersAssigned);
        $this->assertSame(1, $result->created);
    }

    public function test_sync_sets_super_ops_name_extension_attribute(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'phil@acme.com',
                'userPrincipalName' => 'phil@acme.com',
                'displayName' => 'Phil Cooper',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->superOpsNameHintsUpdated);
        $this->assertDatabaseHas('users', [
            'email' => 'phil@acme.com',
            'name' => 'Phil Cooper',
        ]);
    }

    public function test_sync_triggers_superops_scim_provision_on_demand(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $servicePrincipalId = '33333333-3333-3333-3333-333333333333';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_superops_app_id' => $servicePrincipalId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph(
            $tenantId,
            [
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                    'mail' => 'jane@acme.com',
                    'userPrincipalName' => 'jane@acme.com',
                    'displayName' => 'Jane Smith',
                    'accountEnabled' => true,
                    'licensed' => true,
                    'mailboxPurpose' => 'user',
                ],
                'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                    'mail' => 'bob@acme.com',
                    'userPrincipalName' => 'bob@acme.com',
                    'displayName' => 'Bob Jones',
                    'accountEnabled' => true,
                    'licensed' => true,
                    'mailboxPurpose' => 'user',
                ],
            ],
            servicePrincipalId: $servicePrincipalId,
        );

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(2, $result->superOpsUsersProvisioned);
        $this->assertSame([], $result->errors);
    }

    public function test_revert_entra_display_names_strips_mistaken_suffixes(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'ben@acme.com',
                'userPrincipalName' => 'ben@acme.com',
                'displayName' => 'Ben Lister (User Mailbox)',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->revertEntraDisplayNames($client);

        $this->assertSame(1, $result['reverted']);
        $this->assertSame([], $result['errors']);
    }

    public function test_format_super_ops_family_name_uses_surname(): void
    {
        $this->assertSame(
            'Munns (User Mailbox)',
            EntraSyncDisplayName::formatSuperOpsFamilyName(
                'Munns',
                'Hannah',
                'Hannah Munns',
                EntraIdentityType::User,
            ),
        );
    }

    public function test_format_super_ops_family_name_falls_back_to_given_name(): void
    {
        $this->assertSame(
            'Accounts (Shared Mailbox)',
            EntraSyncDisplayName::formatSuperOpsFamilyName(
                '',
                'Accounts',
                'Accounts',
                EntraIdentityType::SharedMailbox,
            ),
        );
    }

    public function test_display_name_formatter_strips_existing_suffix(): void
    {
        $this->assertSame(
            'Jane Smith (Shared Mailbox)',
            EntraSyncDisplayName::format('Jane Smith (User Mailbox)', EntraIdentityType::SharedMailbox),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $usersById
     * @param  list<string>  $initialGroupMembers
     */
    private function fakeTenantSyncGraph(
        string $tenantId,
        array $usersById,
        ?string $groupId = null,
        array $initialGroupMembers = [],
        ?string $servicePrincipalId = null,
        array $initialAppAssignedUsers = [],
        ?string $applicationClientId = null,
    ): void {
        $list = [];
        $groupMembers = $initialGroupMembers;
        $appAssignments = [];

        foreach ($initialAppAssignedUsers as $userId) {
            $appAssignments[$userId] = 'assignment-'.$userId;
        }

        foreach ($usersById as $id => $user) {
            $list[] = [
                'id' => $id,
                'mail' => $user['mail'],
                'userPrincipalName' => $user['userPrincipalName'],
                'displayName' => $user['displayName'],
                'accountEnabled' => $user['accountEnabled'],
            ];
        }

        Http::fake(function ($request) use ($tenantId, $usersById, $list, $groupId, &$groupMembers, $servicePrincipalId, &$appAssignments, $applicationClientId) {
            $url = $request->url();

            if ($url === "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token") {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            if ($applicationClientId && $request->method() === 'GET' && $url === "https://graph.microsoft.com/v1.0/servicePrincipals/{$applicationClientId}") {
                return Http::response(['error' => ['code' => 'Request_ResourceNotFound']], 404);
            }

            if ($applicationClientId && $servicePrincipalId && $request->method() === 'GET' && str_contains($url, "servicePrincipals(appId='{$applicationClientId}')")) {
                return Http::response([
                    'id' => $servicePrincipalId,
                    'displayName' => 'OnIT X Superops',
                    'appId' => $applicationClientId,
                    'appRoles' => [
                        [
                            'id' => '18d11111-1111-1111-1111-111111111111',
                            'displayName' => 'User',
                            'value' => '',
                            'isEnabled' => true,
                            'allowedMemberTypes' => ['User'],
                        ],
                        [
                            'id' => '1a682222-2222-2222-2222-222222222222',
                            'displayName' => 'Default access for SCIM users',
                            'value' => 'User',
                            'description' => 'Default access for SCIM user provisioning',
                            'isEnabled' => true,
                            'allowedMemberTypes' => ['User'],
                        ],
                    ],
                ]);
            }

            if ($servicePrincipalId && $request->method() === 'GET' && preg_match(
                "#/servicePrincipals/{$servicePrincipalId}(\\?|$)#",
                $url,
            ) && ! str_contains($url, '/appRoleAssignedTo')) {
                return Http::response([
                    'id' => $servicePrincipalId,
                    'displayName' => 'SuperOps',
                    'appId' => $applicationClientId ?? '8c46a344-a010-4c78-99b9-df8b9caaba2f',
                    'appRoles' => [
                        [
                            'id' => '18d11111-1111-1111-1111-111111111111',
                            'displayName' => 'User',
                            'value' => '',
                            'isEnabled' => true,
                            'allowedMemberTypes' => ['User'],
                        ],
                        [
                            'id' => '1a682222-2222-2222-2222-222222222222',
                            'displayName' => 'Default access for SCIM users',
                            'value' => 'User',
                            'description' => 'Default access for SCIM user provisioning',
                            'isEnabled' => true,
                            'allowedMemberTypes' => ['User'],
                        ],
                    ],
                ]);
            }

            if ($servicePrincipalId && str_contains($url, "/servicePrincipals/{$servicePrincipalId}/appRoleAssignedTo")) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        'value' => array_map(
                            static fn (string $userId, string $assignmentId): array => [
                                'id' => $assignmentId,
                                'principalId' => $userId,
                                'principalType' => 'User',
                            ],
                            array_keys($appAssignments),
                            array_values($appAssignments),
                        ),
                    ]);
                }

                if ($request->method() === 'DELETE' && preg_match(
                    "#/servicePrincipals/{$servicePrincipalId}/appRoleAssignedTo/(.+)$#",
                    $url,
                    $matches,
                )) {
                    $appAssignments = array_filter(
                        $appAssignments,
                        static fn (string $assignmentId): bool => $assignmentId !== $matches[1],
                    );

                    return Http::response(null, 204);
                }
            }

            if ($servicePrincipalId && $request->method() === 'POST' && preg_match(
                '#/users/([0-9a-f-]+)/appRoleAssignments$#',
                $url,
                $matches,
            )) {
                $userId = $matches[1];
                $appAssignments[$userId] = 'assignment-'.$userId;

                return Http::response(null, 201);
            }

            if ($servicePrincipalId && $request->method() === 'GET' && preg_match(
                "#/servicePrincipals/{$servicePrincipalId}/synchronization/jobs$#",
                $url,
            )) {
                return Http::response([
                    'value' => [
                        [
                            'id' => 'job-11111111-1111-1111-1111-111111111111',
                            'status' => ['state' => 'Active'],
                        ],
                    ],
                ]);
            }

            if ($servicePrincipalId && $request->method() === 'GET' && str_contains($url, '/synchronization/jobs/job-11111111-1111-1111-1111-111111111111/schema')) {
                return Http::response([
                    'synchronizationRules' => [
                        [
                            'id' => 'rule-22222222-2222-2222-2222-222222222222',
                            'sourceDirectoryName' => 'Azure Active Directory',
                            'objectMappings' => [
                                [
                                    'sourceObjectName' => 'User',
                                    'targetObjectName' => 'User',
                                    'enabled' => true,
                                ],
                            ],
                        ],
                    ],
                ]);
            }

            if ($servicePrincipalId && $request->method() === 'POST' && str_contains($url, '/provisionOnDemand')) {
                return Http::response(['value' => []]);
            }

            if ($request->method() === 'PATCH' && preg_match('#/users/([0-9a-f-]+)$#', $url, $matches)) {
                return Http::response(null, 204);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/v1.0/users?')) {
                return Http::response(['value' => $list]);
            }

            if ($groupId && str_contains($url, "/groups/{$groupId}/members/microsoft.graph.user")) {
                return Http::response([
                    'value' => array_map(
                        static fn (string $id): array => ['id' => $id],
                        $groupMembers,
                    ),
                ]);
            }

            if ($groupId && $request->method() === 'POST' && str_contains($url, "/groups/{$groupId}/members/\$ref")) {
                $odataId = $request->data()['@odata.id'] ?? '';
                preg_match('#/directoryObjects/([0-9a-f-]+)$#', $odataId, $matches);
                $userId = $matches[1] ?? null;

                if ($userId && ! in_array($userId, $groupMembers, true)) {
                    $groupMembers[] = $userId;
                }

                return Http::response(null, 204);
            }

            if ($groupId && $request->method() === 'DELETE' && preg_match(
                "#/groups/{$groupId}/members/([0-9a-f-]+)/\\\$ref$#",
                $url,
                $matches,
            )) {
                $groupMembers = array_values(array_filter(
                    $groupMembers,
                    static fn (string $id): bool => $id !== $matches[1],
                ));

                return Http::response(null, 204);
            }

            if (preg_match('#/users/([0-9a-f-]+)/licenseDetails$#', $url, $matches)) {
                $userId = $matches[1];
                $licensed = $usersById[$userId]['licensed'] ?? false;

                return Http::response([
                    'value' => $licensed ? [['skuId' => 'test-sku', 'skuPartNumber' => 'O365_BUSINESS']] : [],
                ]);
            }

            if (preg_match('#/users/([0-9a-f-]+)/mailboxSettings#', $url, $matches)) {
                $userId = $matches[1];
                $purpose = $usersById[$userId]['mailboxPurpose'] ?? null;

                return Http::response([
                    'userPurpose' => $purpose,
                ]);
            }

            return Http::response([], 404);
        });
    }
}
