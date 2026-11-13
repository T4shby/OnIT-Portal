<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Gdap\GdapReconcileService;
use App\Services\Gdap\GdapRoleSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GdapReconcileServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $customerTenant = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.gdap.enabled' => true,
            'services.gdap.partner_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.gdap.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.gdap.client_secret' => 'secret',
            'services.gdap.technician_group_id' => '33333333-3333-3333-3333-333333333333',
        ]);
    }

    public function test_disabled_audit_does_not_call_microsoft(): void
    {
        config(['services.gdap.enabled' => false]);
        Http::fake();

        $client = Client::factory()->create([
            'entra_tenant_id' => $this->customerTenant,
            'is_active' => true,
        ]);

        $result = app(GdapReconcileService::class)->auditAll();

        $this->assertSame(0, $result['checked']);
        Http::assertNothingSent();
        $this->assertNull($client->fresh()->gdap_snapshot);
    }

    public function test_missing_relationship_is_created_and_locked_for_approval(): void
    {
        $this->fakeGraph(relationships: []);

        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'entra_tenant_id' => $this->customerTenant,
            'is_active' => true,
        ]);

        app(GdapReconcileService::class)->auditAll($client->id);

        $snapshot = $client->fresh()->gdap_snapshot;
        $this->assertSame('approval_pending', $snapshot['status']);
        $this->assertTrue($snapshot['blocks_connect']);
        $this->assertSame('rel-new', $snapshot['relationship_id']);
        $this->assertStringContainsString('rel-new', $snapshot['approval_url']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), 'delegatedAdminRelationships')) {
                return false;
            }
            $roles = collect($request->data()['accessDetails']['unifiedRoles'] ?? [])->pluck('roleDefinitionId');

            return $roles->contains(GdapRoleSet::ids()[0])
                && ! $roles->contains(GdapRoleSet::GLOBAL_ADMINISTRATOR)
                && ($request->data()['autoExtendDuration'] ?? null) === 'P180D';
        });
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rel-new/requests')
            && ($request->data()['action'] ?? null) === 'lockForApproval');
    }

    public function test_pending_request_is_left_alone(): void
    {
        $this->fakeGraph(relationships: [[
            'id' => 'rel-wait',
            'displayName' => 'Existing',
            'status' => 'approvalPending',
            'customer' => ['tenantId' => $this->customerTenant],
            'accessDetails' => ['unifiedRoles' => []],
            'autoExtendDuration' => 'P180D',
        ]]);

        $client = Client::factory()->create([
            'entra_tenant_id' => $this->customerTenant,
            'is_active' => true,
        ]);

        app(GdapReconcileService::class)->auditAll($client->id);

        $snapshot = $client->fresh()->gdap_snapshot;
        $this->assertSame('approval_pending', $snapshot['status']);
        $this->assertSame('rel-wait', $snapshot['relationship_id']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'delegatedAdminRelationships'));
    }

    public function test_active_relationship_gets_the_technician_group(): void
    {
        $this->fakeGraph(relationships: [$this->activeRelationship()], assignments: []);

        $client = Client::factory()->create([
            'entra_tenant_id' => $this->customerTenant,
            'is_active' => true,
        ]);

        app(GdapReconcileService::class)->auditAll($client->id);

        $snapshot = $client->fresh()->gdap_snapshot;
        $this->assertSame('unassigned', $snapshot['status']);
        $this->assertTrue($snapshot['blocks_connect']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rel-live/accessAssignments')
            && ($request->data()['accessContainer']['accessContainerId'] ?? null) === '33333333-3333-3333-3333-333333333333');
    }

    public function test_assigned_relationship_enables_auto_extend(): void
    {
        $this->fakeGraph(
            relationships: [$this->activeRelationship('PT0S')],
            assignments: [[
                'id' => 'asg-1',
                'status' => 'active',
                'accessContainer' => [
                    'accessContainerId' => '33333333-3333-3333-3333-333333333333',
                    'accessContainerType' => 'securityGroup',
                ],
                'accessDetails' => [
                    'unifiedRoles' => array_map(
                        fn (string $id): array => ['roleDefinitionId' => $id],
                        GdapRoleSet::ids(),
                    ),
                ],
            ]],
        );

        $client = Client::factory()->create([
            'entra_tenant_id' => $this->customerTenant,
            'is_active' => true,
        ]);

        app(GdapReconcileService::class)->auditAll($client->id);

        $snapshot = $client->fresh()->gdap_snapshot;
        $this->assertSame('ready', $snapshot['status']);
        $this->assertTrue($snapshot['auto_extend']);
        $this->assertFalse($snapshot['blocks_connect']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rel-live')
            && ($request->data()['autoExtendDuration'] ?? null) === 'P180D');
    }

    public function test_unknown_domain_does_not_block_connect(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'openid-configuration')) {
                return Http::response(['error' => 'not found'], 400);
            }

            return Http::response(['access_token' => 'tok', 'value' => []], 200);
        });

        $client = Client::factory()->create([
            'primary_domain' => 'not-a-microsoft-tenant.example',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);

        app(GdapReconcileService::class)->auditAll($client->id);

        $snapshot = $client->fresh()->gdap_snapshot;
        $this->assertSame('needs_tenant', $snapshot['status']);
        $this->assertFalse($snapshot['blocks_connect']);
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @param  list<array<string, mixed>>  $assignments
     */
    private function fakeGraph(array $relationships, array $assignments = []): void
    {
        Http::fake(function (Request $request) use ($relationships, $assignments) {
            $url = $request->url();

            if (str_contains($url, 'oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'tok'], 200);
            }

            if (str_contains($url, '/requests')) {
                return Http::response(['id' => 'req-1'], 201);
            }

            if (str_contains($url, '/accessAssignments')) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'id' => 'asg-new',
                        'status' => 'pending',
                        'accessContainer' => [
                            'accessContainerId' => '33333333-3333-3333-3333-333333333333',
                        ],
                        'accessDetails' => ['unifiedRoles' => []],
                    ], 201);
                }

                return Http::response(['value' => $assignments], 200);
            }

            if ($request->method() === 'POST' && str_contains($url, 'delegatedAdminRelationships')) {
                return Http::response([
                    'id' => 'rel-new',
                    'displayName' => 'On IT new',
                    'status' => 'created',
                    'customer' => ['tenantId' => $this->customerTenant],
                    'accessDetails' => ['unifiedRoles' => []],
                    'autoExtendDuration' => 'P180D',
                ], 201);
            }

            if ($request->method() === 'PATCH') {
                return Http::response([
                    'id' => 'rel-live',
                    'autoExtendDuration' => 'P180D',
                ], 200);
            }

            if ($request->method() === 'GET' && str_contains($url, 'delegatedAdminRelationships')) {
                return Http::response(['value' => $relationships], 200);
            }

            return Http::response('unexpected '.$request->method().' '.$url, 500);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function activeRelationship(string $autoExtend = 'P180D'): array
    {
        return [
            'id' => 'rel-live',
            '@odata.etag' => 'W/"9"',
            'displayName' => 'On IT live',
            'status' => 'active',
            'customer' => ['tenantId' => $this->customerTenant, 'displayName' => 'Acme'],
            'accessDetails' => [
                'unifiedRoles' => array_map(
                    fn (string $id): array => ['roleDefinitionId' => $id],
                    GdapRoleSet::ids(),
                ),
            ],
            'autoExtendDuration' => $autoExtend,
            'endDateTime' => '2028-06-01T00:00:00Z',
        ];
    }
}
