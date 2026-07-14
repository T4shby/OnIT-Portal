<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Enums\UserRole;
use App\Support\AdminConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperOpsRequesterSsoConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_complete_page_ticks_checklist_and_explains_success(): void
    {
        $client = Client::factory()->create([
            'name' => '3R Systems Limited',
            'entra_tenant_id' => '992690d7-3656-4226-82a7-df2cde960149',
        ]);

        $response = $this->get(route('integrations.superops.requester-sso.consent-complete', [
            'admin_consent' => 'True',
            'tenant' => '992690d7-3656-4226-82a7-df2cde960149',
            'state' => AdminConsentState::encode($client->id),
        ]));

        $response->assertOk();
        $response->assertSee('SuperOps SSO Accept complete', false);
        $response->assertSee('usauth.superops.ai', false);
        $this->assertTrue((bool) (($client->fresh()->onboarding_checklist ?? [])['superops_client_sso_configured'] ?? false));
    }

    public function test_authenticated_technician_is_returned_to_edit_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => '3R Systems Limited']);

        $response = $this->actingAs($admin)->get(route('integrations.superops.requester-sso.consent-complete', [
            'admin_consent' => 'True',
            'tenant' => '992690d7-3656-4226-82a7-df2cde960149',
            'state' => AdminConsentState::encode($client->id),
        ]));

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');
    }
}
