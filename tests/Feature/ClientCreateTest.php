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
}
