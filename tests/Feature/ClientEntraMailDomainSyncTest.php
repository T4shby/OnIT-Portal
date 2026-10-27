<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientEntraMailDomainSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_from_microsoft_seeds_primary_and_shows_on_edit(): void
    {
        Bus::fake();
        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/domains*' => Http::response([
                'value' => [
                    [
                        'id' => 'acme.co.uk',
                        'isVerified' => true,
                        'isDefault' => true,
                        'isInitial' => false,
                    ],
                ],
            ]),
            'graph.microsoft.com/v1.0/users*' => Http::response(['value' => []]),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Acme',
            'entra_tenant_id' => '33333333-3333-3333-3333-333333333333',
            'primary_domain' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.domains.sync-entra', $client))
            ->assertRedirect();

        $this->assertSame('acme.co.uk', $client->fresh()->primary_domain);

        $this->actingAs($admin)
            ->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Import from Microsoft')
            ->assertSee('acme.co.uk');
    }
}
