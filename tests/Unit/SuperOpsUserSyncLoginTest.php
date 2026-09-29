<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperOpsUserSyncLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);
    }

    public function test_login_sync_never_binds_or_links_when_the_client_has_no_superops_account(): void
    {
        // Email matches a requester that lives under a *different* SuperOps client.
        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => [
                    'getClientUserList' => [
                        'userList' => [[
                            'userId' => 'req-42',
                            'email' => 'jo@unlinked.example',
                            'client' => json_encode(['accountId' => 'someone-elses-account']),
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['superops_account_id' => null]);
        $user = User::factory()->create(['client_id' => $client->id, 'email' => 'jo@unlinked.example']);

        $this->assertFalse(app(SuperOpsUserSyncService::class)->syncUser($user));

        $this->assertNull($user->fresh()->superops_user_id);
        $this->assertNull($client->fresh()->superops_account_id);
        Http::assertNothingSent();
    }

    public function test_login_sync_lookup_is_scoped_to_the_users_superops_client(): void
    {
        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => ['getClientUserList' => ['userList' => [['userId' => 'req-7', 'email' => 'jo@acme.example']]]],
            ], 200),
        ]);

        $client = Client::factory()->create(['superops_account_id' => 'acc-acme']);
        $user = User::factory()->create(['client_id' => $client->id, 'email' => 'jo@acme.example']);

        $this->assertTrue(app(SuperOpsUserSyncService::class)->syncUser($user));
        $this->assertSame('req-7', $user->fresh()->superops_user_id);
        Http::assertSent(fn ($request) => ($request->data()['variables']['input']['clientId'] ?? null) === 'acc-acme');
    }
}
