<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HuntressSecurityCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentsCache(): array
    {
        return [
            'organization_id' => 'org-acme',
            'active_count' => 2,
            'resolved_count' => 1,
            'last_refreshed_at' => now()->toIso8601String(),
            'incidents' => [
                [
                    'id' => '100',
                    'organization_id' => 'org-acme',
                    'subject' => 'Case for jane',
                    'status' => 'open',
                    'severity' => 'high',
                    'summary' => 'Detected on machine for jane.doe@acme.test',
                    'body' => null,
                    'sent_at' => now()->toIso8601String(),
                    'closed_at' => null,
                    'updated_at' => null,
                    'platform' => 'windows',
                    'indicator_types' => [],
                    'remediations' => [],
                    'related_emails' => ['jane.doe@acme.test'],
                ],
                [
                    'id' => '200',
                    'organization_id' => 'org-acme',
                    'subject' => 'Case for bob only',
                    'status' => 'open',
                    'severity' => 'medium',
                    'summary' => 'bob.smith@acme.test device alert',
                    'body' => null,
                    'sent_at' => now()->toIso8601String(),
                    'closed_at' => null,
                    'updated_at' => null,
                    'platform' => 'windows',
                    'indicator_types' => [],
                    'remediations' => [],
                    'related_emails' => ['bob.smith@acme.test'],
                ],
                [
                    'id' => '101',
                    'organization_id' => 'org-acme',
                    'subject' => 'Closed case bob',
                    'status' => 'closed',
                    'severity' => 'low',
                    'summary' => 'Resolved for bob.smith@acme.test',
                    'body' => null,
                    'sent_at' => now()->subDay()->toIso8601String(),
                    'closed_at' => now()->toIso8601String(),
                    'updated_at' => null,
                    'platform' => 'windows',
                    'indicator_types' => [],
                    'remediations' => [],
                    'related_emails' => ['bob.smith@acme.test'],
                ],
            ],
        ];
    }

    public function test_client_admin_sees_all_org_cases(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'huntress_organization_id' => 'org-acme',
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'email' => 'admin@acme.test',
        ]);

        Cache::put("client:{$client->id}:huntress-incidents:v1", $this->incidentsCache(), now()->addHour());

        $this->actingAs($admin)
            ->get(route('security.huntress.index'))
            ->assertOk()
            ->assertSee('Case for jane')
            ->assertSee('Case for bob only')
            ->assertSee('Closed case bob')
            ->assertSeeText('every case');
    }

    public function test_regular_user_only_sees_their_own_cases(): void
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'huntress_organization_id' => 'org-acme',
        ]);
        $jane = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'jane.doe@acme.test',
            'name' => 'Jane Doe',
        ]);

        Cache::put("client:{$client->id}:huntress-incidents:v1", $this->incidentsCache(), now()->addHour());

        $this->actingAs($jane)
            ->get(route('security.huntress.index'))
            ->assertOk()
            ->assertSee('Case for jane')
            ->assertDontSee('Case for bob only')
            ->assertDontSee('Closed case bob')
            ->assertSeeText('only see cases linked to you');

        $this->actingAs($jane)
            ->get(route('security.huntress.show', '100'))
            ->assertOk()
            ->assertSee('Case for jane');

        $this->actingAs($jane)
            ->get(route('security.huntress.show', '200'))
            ->assertNotFound();
    }

    public function test_regular_user_cannot_refresh_org_cache(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 'org-acme']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'jane.doe@acme.test',
        ]);

        $this->actingAs($user)
            ->post(route('security.huntress.refresh'))
            ->assertForbidden();
    }

    public function test_other_client_admin_cannot_open_foreign_incident(): void
    {
        $clientA = Client::factory()->create(['huntress_organization_id' => 'org-a']);
        $clientB = Client::factory()->create(['huntress_organization_id' => 'org-b']);
        $adminB = User::factory()->create([
            'client_id' => $clientB->id,
            'role' => UserRole::ClientAdmin,
        ]);

        Cache::put("client:{$clientA->id}:huntress-incidents:v1", [
            'organization_id' => 'org-a',
            'active_count' => 1,
            'resolved_count' => 0,
            'last_refreshed_at' => now()->toIso8601String(),
            'incidents' => [[
                'id' => '999',
                'organization_id' => 'org-a',
                'subject' => 'Secret A only',
                'status' => 'open',
                'summary' => 'private',
                'related_emails' => [],
            ]],
        ], now()->addHour());

        $this->actingAs($adminB)
            ->get(route('security.huntress.show', '999'))
            ->assertNotFound();
    }

    public function test_staff_with_client_access_can_view_all_cases(): void
    {
        $client = Client::factory()->create([
            'name' => 'Managed Co',
            'huntress_organization_id' => 'org-acme',
        ]);
        $am = User::factory()->create(['role' => UserRole::AccountManager]);
        $am->assignedClients()->attach($client->id);

        Cache::put("client:{$client->id}:huntress-incidents:v1", $this->incidentsCache(), now()->addHour());
        Cache::put("client:{$client->id}:huntress-security:v1", [
            'agents_total' => 22,
            'agents_unresponsive' => 0,
            'open_incidents' => 2,
            'resolved_incidents' => 1,
            'edr_isolated_agents' => 0,
            'last_refreshed_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addHour());

        $this->actingAs($am)
            ->get(route('admin.clients.security.huntress', $client))
            ->assertOk()
            ->assertSee('Managed Co')
            ->assertSee('Huntress')
            ->assertSee('Active cases')
            ->assertSee('Case for jane')
            ->assertSee('Case for bob only')
            ->assertSee('Open in Huntress');
    }
}
