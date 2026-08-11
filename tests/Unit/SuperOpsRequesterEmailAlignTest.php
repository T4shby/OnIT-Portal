<?php

namespace Tests\Unit;

use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
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

        $result = app(SuperOpsUserSyncService::class)->alignRequesterPrimaryEmails($client, []);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['bound']);
        $this->assertSame([], $result['unmatched']);
        Http::assertSentCount(2);
    }

    public function test_aligns_via_graph_alias_when_local_part_and_domain_change(): void
    {
        config([
            'services.superops.api_token' => 'test-token',
            'services.superops.subdomain' => 'onit-ltd',
            'services.superops.region' => 'us',
        ]);

        $client = Client::factory()->create([
            'superops_account_id' => 'acct-1',
        ]);

        $portalUser = User::factory()->create([
            'client_id' => $client->id,
            'email' => 'namelast@domain.net',
            'name' => 'Name Last',
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
                                    'userId' => 'so-9',
                                    'email' => 'name.last@domain.com',
                                ],
                            ],
                            'listInfo' => ['hasMore' => false],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'updateClientUser' => [
                            'userId' => 'so-9',
                            'email' => 'namelast@domain.net',
                        ],
                    ],
                ]),
        ]);

        $result = app(SuperOpsUserSyncService::class)->alignRequesterPrimaryEmails($client, [[
            'to' => 'namelast@domain.net',
            'aliases' => ['name.last@domain.com', 'smtp:name.last@domain.com'],
            'superops_user_id' => null,
            'portal_user_id' => $portalUser->id,
            'label' => 'Name Last <namelast@domain.net>',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['unmatched']);
        $portalUser->refresh();
        $this->assertSame('so-9', $portalUser->superops_user_id);
    }

    public function test_normalize_graph_email_aliases_parses_proxy_addresses(): void
    {
        $aliases = app(MicrosoftGraphClient::class)->normalizeGraphEmailAliases([
            'mail' => 'jane@new.com',
            'userPrincipalName' => 'jane@new.com',
            'otherMails' => ['jane@alias.com'],
            'proxyAddresses' => [
                'SMTP:jane@new.com',
                'smtp:jane@old.com',
            ],
        ]);

        $this->assertContains('jane@new.com', $aliases);
        $this->assertContains('jane@old.com', $aliases);
        $this->assertContains('jane@alias.com', $aliases);
    }
}
