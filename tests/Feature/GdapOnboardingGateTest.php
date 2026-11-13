<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GdapOnboardingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_is_scheduled_nightly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'portal:audit-gdap'));

        $this->assertNotNull($event);
        $this->assertSame('40 4 * * *', $event->expression);
    }

    public function test_connect_stays_available_while_gdap_is_off(): void
    {
        config([
            'services.gdap.enabled' => false,
            'services.entra_sync.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.azure.redirect' => 'https://app.onit.test/auth/microsoft/callback',
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'primary_domain' => 'acme.example',
            'entra_tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertDontSee('Check GDAP now')
            ->assertSee('adminconsent', false);
    }

    public function test_connect_is_blocked_until_gdap_is_ready(): void
    {
        config([
            'services.gdap.enabled' => true,
            'services.gdap.partner_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.gdap.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.gdap.client_secret' => 'secret',
            'services.entra_sync.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.azure.redirect' => 'https://app.onit.test/auth/microsoft/callback',
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Blocked Co',
            'primary_domain' => 'blocked.example',
            'entra_tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Check GDAP now')
            ->assertSee('Connect stays blocked')
            ->assertDontSee('adminconsent', false);
    }

    public function test_integration_health_lists_clients_waiting_on_approval(): void
    {
        config([
            'services.gdap.enabled' => true,
            'services.gdap.partner_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.gdap.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.gdap.client_secret' => 'secret',
        ]);

        Client::factory()->create([
            'name' => 'Waiting Co',
            'is_active' => true,
            'gdap_snapshot' => [
                'status' => 'approval_pending',
                'blocks_connect' => true,
                'summary' => 'Waiting for a Global Admin in the customer tenant to approve GDAP.',
            ],
        ]);

        $overview = app(IntegrationHealthService::class)->overview();

        $this->assertSame(1, $overview['gdap']['problem_count']);
        $this->assertSame('Waiting Co', $overview['gdap']['problems'][0]['client_name']);
        $this->assertStringContainsString('awaiting approval', (string) $overview['gdap']['headline']);
    }

    public function test_check_now_queues_an_audit_for_that_client(): void
    {
        config([
            'services.gdap.enabled' => true,
            'services.gdap.partner_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'services.gdap.client_id' => '22222222-2222-2222-2222-222222222222',
            'services.gdap.client_secret' => 'secret',
            'services.gdap.technician_group_id' => '33333333-3333-3333-3333-333333333333',
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'tok'], 200);
            }
            if (str_contains($request->url(), '/requests')) {
                return Http::response(['id' => 'req-1'], 201);
            }
            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 'rel-from-button',
                    'displayName' => 'On IT button',
                    'status' => 'created',
                    'customer' => ['tenantId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'],
                    'accessDetails' => ['unifiedRoles' => []],
                    'autoExtendDuration' => 'P180D',
                ], 201);
            }

            return Http::response(['value' => []], 200);
        });

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.gdap.refresh', $client))
            ->assertRedirect(route('admin.clients.edit', $client));

        $this->assertSame('approval_pending', $client->fresh()->gdap_snapshot['status']);
        $this->assertSame('rel-from-button', $client->fresh()->gdap_snapshot['relationship_id']);
    }
}
