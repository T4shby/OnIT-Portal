<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortalCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_home_uses_friendly_copy_not_beta_or_ops_jargon(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Ltd']);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your IT')
            ->assertSee('at a glance')
            ->assertSee('Something look off?')
            ->assertSee('Tell the Service Desk')
            ->assertDontSee('Beta')
            ->assertDontSee('figures may not be quite right')
            ->assertDontSee('Never loaded')
            ->assertDontSee('via MDR')
            ->assertDontSee('All systems protected');
    }

    public function test_client_support_list_does_not_leak_env_or_ops_labels(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        $this->actingAs($user)
            ->get(route('support.index'))
            ->assertOk()
            ->assertDontSee('SUPEROPS_API_TOKEN')
            ->assertDontSee('SuperOps not configured')
            ->assertDontSee('Open SuperOps');
    }
}
