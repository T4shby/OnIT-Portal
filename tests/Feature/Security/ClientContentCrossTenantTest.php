<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portal links take client_id from the request. The policy only proves "is staff"
 * (create) or "may touch the record's current client" (update), so the submitted
 * client must be checked too.
 */
class ClientContentCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Client $assigned;

    private Client $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assigned = Client::factory()->create();
        $this->other = Client::factory()->create();

        $this->manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $this->manager->assignedClients()->attach($this->assigned);
    }

    public function test_account_manager_cannot_create_portal_link_for_unassigned_client(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.portal-links.store'), [
                'name' => 'Sneaky',
                'link_type' => 'external',
                'url' => 'https://example.com',
                'client_id' => $this->other->id,
                'display_order' => 0,
            ])
            ->assertSessionHasErrors('client_id');

        $this->assertDatabaseMissing('portal_links', ['name' => 'Sneaky']);
    }

    public function test_account_manager_can_still_create_global_portal_link(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.portal-links.store'), [
                'name' => 'Global help',
                'link_type' => 'external',
                'url' => 'https://example.com/help',
                'client_id' => '',
                'display_order' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('portal_links', ['name' => 'Global help', 'client_id' => null]);
    }
}
