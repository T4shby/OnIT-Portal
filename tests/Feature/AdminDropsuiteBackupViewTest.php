<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDropsuiteBackupViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_open_dropsuite_page_when_entitled_and_mapped(): void
    {
        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.reseller_token' => 'r',
            'services.dropsuite.auth_token' => 'a',
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'YorPower',
            'dropsuite_organization_id' => '177210-12',
            'product_entitlements' => [
                'dropsuite' => ['entitled' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.clients.dropsuite', $client))
            ->assertOk()
            ->assertSee('Dropsuite')
            ->assertSee('YorPower');
    }

    public function test_client_admin_cannot_use_staff_dropsuite_route(): void
    {
        $client = Client::factory()->create([
            'dropsuite_organization_id' => '1',
            'product_entitlements' => ['dropsuite' => ['entitled' => true]],
        ]);
        $user = User::factory()->create([
            'role' => UserRole::ClientAdmin,
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.clients.dropsuite', $client))
            ->assertForbidden();
    }
}
