<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_client_redirects_to_edit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.store'), [
                'name' => 'Ductec LTD',
                'superops_account_id' => '3425667307281944576',
                'superops_sso_enabled' => '1',
                'is_active' => '1',
            ]);

        $client = Client::where('name', 'Ductec LTD')->firstOrFail();

        $response->assertRedirect(route('admin.clients.edit', $client));
    }

    public function test_create_page_does_not_show_setup_checklist(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)
            ->get(route('admin.clients.create'));

        $response->assertOk();
        $response->assertSee('Step 1 of 2', false);
        $response->assertSee('Add the client record', false);
        $response->assertSee('Step 2 of 2', false);
        $response->assertDontSee('Save checklist', false);
        $response->assertDontSee('name="entra_tenant_id"', false);
        $response->assertDontSee('name="entra_group_id"', false);
        $response->assertDontSee('name="entra_sync_enabled"', false);
        $response->assertDontSee('name="entra_superops_app_id"', false);
        $response->assertDontSee('name="entra_superops_sso_app_id"', false);
    }

    public function test_create_page_does_not_accept_entra_fields_on_store(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->post(route('admin.clients.store'), [
                'name' => 'MXVI',
                'entra_tenant_id' => '664302e2-8885-4ec1-9958-12233cbbeedb',
                'entra_group_id' => 'd77ea486-e2fb-4dbe-b0a9-f322fd8cf1c3',
                'entra_superops_app_id' => '8c46a344-a010-4c78-99b9-df8b9caaba2f',
                'entra_superops_sso_app_id' => '4d8b28c0-79ae-4fa6-b7ef-5bd03e704296',
                'entra_sync_enabled' => '1',
                'is_active' => '1',
            ]);

        $client = Client::where('name', 'MXVI')->firstOrFail();

        $this->assertNull($client->entra_tenant_id);
        $this->assertNull($client->entra_group_id);
        $this->assertNull($client->entra_superops_app_id);
        $this->assertNull($client->entra_superops_sso_app_id);
        $this->assertFalse($client->entra_sync_enabled);
    }

    public function test_edit_page_uses_update_not_create(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => 'Ductec LTD']);

        $response = $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client));

        $response->assertOk();
        $response->assertSee('Edit Client — Ductec LTD', false);
        $response->assertSee('Save client', false);
        $response->assertDontSee('Create client', false);
    }

    public function test_updating_client_redirects_back_to_edit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => 'Ductec LTD']);

        $response = $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => 'Ductec LTD',
                'superops_account_id' => '3425667307281944576',
                'superops_sso_enabled' => '1',
                'entra_superops_sso_app_id' => '4d8b28c0-79ae-4fa6-b7ef-5bd03e704296',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $this->assertSame(
            '4d8b28c0-79ae-4fa6-b7ef-5bd03e704296',
            $client->fresh()->entra_superops_sso_app_id,
        );
    }

    public function test_edit_page_admin_consent_uses_client_name_not_pilot_example(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'MXVI',
            'entra_tenant_id' => '664302e2-8885-4ec1-9958-12233cbbeedb',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client));

        $response->assertOk();
        $response->assertSee(
            'Sign in with the <strong class="onboarding-manual__emph">On IT technician account that has the required GDAP admin role</strong> for MXVI',
            false,
        );
        $response->assertDontSee('Ductec', false);
    }

    public function test_saving_entra_group_id_completes_security_group_step(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Ductec LTD',
            'entra_tenant_id' => 'f95a6006-f34e-4634-8678-32ab856d9756',
            'entra_group_id' => null,
        ]);

        $groupId = 'd77ea486-e2fb-4dbe-b0a9-f322fd8cffc3';

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => 'Ductec LTD',
                'entra_tenant_id' => 'f95a6006-f34e-4634-8678-32ab856d9756',
                'entra_group_id' => ' '.$groupId.' ',
                'entra_sync_enabled' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.clients.edit', $client));

        $client->refresh();

        $this->assertSame($groupId, $client->entra_group_id);
        $this->assertTrue($client->onboarding_checklist['entra_group_created']);

        $step = collect(app(\App\Services\ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created');

        $this->assertTrue($step['complete']);
    }
}
