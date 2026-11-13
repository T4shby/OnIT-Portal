<?php

namespace Tests\Unit;

use App\Services\Gdap\GdapRelationshipAssessor;
use App\Services\Gdap\GdapRoleSet;
use Tests\TestCase;

class GdapRelationshipAssessorTest extends TestCase
{
    private GdapRelationshipAssessor $assessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessor = new GdapRelationshipAssessor;
    }

    public function test_missing_relationship_asks_for_a_new_request(): void
    {
        $plan = $this->assess([]);

        $this->assertSame('create', $plan['action']);
        $this->assertSame('missing', $plan['status']);
        $this->assertTrue($plan['blocks_connect']);
    }

    public function test_approval_pending_does_not_create_another_request(): void
    {
        $plan = $this->assess([
            $this->relationship('rel-pending', 'approvalPending'),
        ]);

        $this->assertSame('none', $plan['action']);
        $this->assertSame('approval_pending', $plan['status']);
        $this->assertTrue($plan['blocks_connect']);
        $this->assertStringContainsString('rel-pending', (string) $plan['approval_url']);
    }

    public function test_created_request_is_locked_for_approval(): void
    {
        $plan = $this->assess([
            $this->relationship('rel-draft', 'created'),
        ]);

        $this->assertSame('lock', $plan['action']);
        $this->assertSame('approval_pending', $plan['status']);
    }

    public function test_active_relationship_without_a_group_is_assigned(): void
    {
        $plan = $this->assess([
            $this->relationship('rel-active', 'active', GdapRoleSet::ids()),
        ]);

        $this->assertSame('assign', $plan['action']);
        $this->assertSame('unassigned', $plan['status']);
        $this->assertTrue($plan['blocks_connect']);
    }

    public function test_pending_group_assignment_waits(): void
    {
        $plan = $this->assess(
            [$this->relationship('rel-active', 'active', GdapRoleSet::ids())],
            ['rel-active' => [$this->assignment('pending')]],
        );

        $this->assertSame('none', $plan['action']);
        $this->assertSame('unassigned', $plan['status']);
        $this->assertTrue($plan['blocks_connect']);
    }

    public function test_active_assigned_relationship_turns_auto_extend_on(): void
    {
        $plan = $this->assess(
            [$this->relationship('rel-active', 'active', GdapRoleSet::ids(), 'PT0S', '2028-01-01T00:00:00Z')],
            ['rel-active' => [$this->assignment('active', GdapRoleSet::ids())]],
        );

        $this->assertSame('auto_extend', $plan['action']);
        $this->assertSame('ready', $plan['status']);
        $this->assertFalse($plan['blocks_connect']);
    }

    public function test_global_administrator_relationship_is_not_auto_extended(): void
    {
        $roles = array_merge(GdapRoleSet::ids(), [GdapRoleSet::GLOBAL_ADMINISTRATOR]);
        $plan = $this->assess(
            [$this->relationship('rel-ga', 'active', $roles, 'PT0S', '2026-11-20T00:00:00Z')],
            ['rel-ga' => [$this->assignment('active', $roles)]],
        );

        $this->assertSame('none', $plan['action']);
        $this->assertSame('expiring', $plan['status']);
        $this->assertFalse($plan['blocks_connect']);
        $this->assertStringContainsString('Global Administrator', $plan['summary']);
    }

    public function test_active_relationship_missing_a_standing_role_requests_a_replacement(): void
    {
        $roles = array_values(array_filter(
            GdapRoleSet::ids(),
            fn (string $id): bool => $id !== GdapRoleSet::ids()[0],
        ));

        $plan = $this->assess([
            $this->relationship('rel-thin', 'active', $roles),
        ]);

        $this->assertSame('create', $plan['action']);
        $this->assertSame('incomplete', $plan['status']);
        $this->assertContains('Privileged Role Administrator', $plan['missing_roles']);
    }

    public function test_in_flight_request_wins_over_an_incomplete_active_relationship(): void
    {
        $roles = array_slice(GdapRoleSet::ids(), 1);
        $plan = $this->assess([
            $this->relationship('rel-thin', 'active', $roles),
            $this->relationship('rel-wait', 'approvalPending', GdapRoleSet::ids()),
        ]);

        $this->assertSame('none', $plan['action']);
        $this->assertSame('rel-wait', $plan['relationship_id']);
    }

    public function test_expired_relationship_is_replaced(): void
    {
        $plan = $this->assess([
            $this->relationship('rel-old', 'expired', GdapRoleSet::ids()),
            $this->relationship('rel-dead', 'terminated', GdapRoleSet::ids()),
        ]);

        $this->assertSame('create', $plan['action']);
        $this->assertSame('missing', $plan['status']);
    }

    public function test_graph_expiring_status_is_not_treated_as_usable_access(): void
    {
        $plan = $this->assess([
            $this->relationship('rel-past', 'expiring', GdapRoleSet::ids()),
        ]);

        $this->assertSame('create', $plan['action']);
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @param  array<string, list<array<string, mixed>>>  $assignments
     * @return array<string, mixed>
     */
    private function assess(array $relationships, array $assignments = []): array
    {
        return $this->assessor->assess(
            $relationships,
            $assignments,
            '33333333-3333-3333-3333-333333333333',
            GdapRoleSet::ids(),
            new \DateTimeImmutable('2026-11-12T12:00:00Z'),
            30,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function relationship(
        string $id,
        string $status,
        array $roles = [],
        string $autoExtend = 'PT0S',
        ?string $endAt = null,
    ): array {
        return [
            'id' => $id,
            'etag' => 'W/"1"',
            'display_name' => 'On IT '.$id,
            'status' => $status,
            'customer_tenant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'role_ids' => $roles,
            'auto_extend' => $autoExtend,
            'end_at' => $endAt,
        ];
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function assignment(string $status, array $roles = []): array
    {
        return [
            'id' => 'asg-1',
            'etag' => 'W/"2"',
            'status' => $status,
            'group_id' => '33333333-3333-3333-3333-333333333333',
            'role_ids' => $roles,
        ];
    }
}
