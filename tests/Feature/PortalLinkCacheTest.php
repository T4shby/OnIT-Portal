<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\PortalLink;
use App\Models\User;
use App\Services\ExternalServicesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers' link lists are cached per (client, role) for 5 minutes. Changing a
 * global link, or moving a link off a client, must drop those cached lists.
 */
class PortalLinkCacheTest extends TestCase
{
    use RefreshDatabase;

    private function linkNamesFor(User $user): array
    {
        return app(ExternalServicesService::class)->getLinksForUser($user)->pluck('name')->all();
    }

    public function test_deleting_a_global_link_removes_it_from_customers_cached_lists(): void
    {
        $client = Client::factory()->create();
        $customer = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $link = PortalLink::create(['client_id' => null, 'name' => 'Wrong global link', 'url' => 'https://wrong.example', 'link_type' => 'external', 'is_active' => true]);

        $this->assertContains('Wrong global link', $this->linkNamesFor($customer));

        $this->actingAs($admin)->delete(route('admin.portal-links.destroy', $link))->assertRedirect();

        $this->assertNotContains('Wrong global link', $this->linkNamesFor($customer->fresh()));
    }

    public function test_moving_a_link_to_another_client_removes_it_from_the_old_clients_list(): void
    {
        $from = Client::factory()->create();
        $to = Client::factory()->create();
        $customer = User::factory()->create(['client_id' => $from->id, 'role' => UserRole::ClientAdmin]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $link = PortalLink::create(['client_id' => $from->id, 'name' => 'Client specific link', 'url' => 'https://from.example', 'link_type' => 'external', 'is_active' => true, 'display_order' => 0]);

        $this->assertContains('Client specific link', $this->linkNamesFor($customer));

        $this->actingAs($admin)->put(route('admin.portal-links.update', $link), [
            'client_id' => $to->id,
            'name' => 'Client specific link',
            'link_type' => 'external',
            'url' => 'https://from.example',
            'display_order' => 0,
            'is_active' => '1',
            'open_in_new_tab' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNotContains('Client specific link', $this->linkNamesFor($customer->fresh()));
    }
}
