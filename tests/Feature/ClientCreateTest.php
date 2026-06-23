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
        $response->assertSee('setup checklist opens on the next screen', false);
        $response->assertDontSee('Save checklist', false);
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
        $response->assertDontSee('>Create<', false);
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
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
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
