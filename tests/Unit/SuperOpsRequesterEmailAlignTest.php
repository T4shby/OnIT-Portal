<?php

namespace Tests\Unit;

use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsUserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperOpsRequesterEmailAlignTest extends TestCase
{
    use RefreshDatabase;

    public function test_aligns_superops_email_by_local_part_when_domain_changed(): void
    {
        config([
            'services.superops.api_token' => 'test-token',
            'services.superops.subdomain' => 'onit-ltd',
            'services.superops.region' => 'us',
        ]);

        $client = Client::factory()->create([
            'superops_account_id' => '868759971385333760',
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'andy.johnson@mxvi.com',
            'role' => UserRole::ClientRequester,
            'provisioned_by' => UserProvisionSource::EntraSync,
            'is_active' => true,
        ]);

        Http::fake([
            'api.superops.ai/msp' => Http::sequence()
                ->push([
                    'data' => [
                        'getClientUserList' => [
                            'userList' => [
                                [
                                    'userId' => 'so-1',
                                    'email' => 'andy.johnson@mxvi.net',
                                ],
                            ],
                            'listInfo' => ['hasMore' => false],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'updateClientUser' => [
                            'userId' => 'so-1',
                            'email' => 'andy.johnson@mxvi.com',
                        ],
                    ],
                ]),
        ]);

        $updated = app(SuperOpsUserSyncService::class)->alignRequesterPrimaryEmails($client, []);

        $this->assertSame(1, $updated);
        Http::assertSentCount(2);
    }
}
