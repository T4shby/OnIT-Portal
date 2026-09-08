<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportTicketShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_ticket_when_superops_ticket_type_has_no_description_field(): void
    {
        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);

        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response([
                    'data' => [
                        'getTicketConversationList' => [
                            ['content' => 'Opening details', 'time' => '2026-08-24T12:00:00.000', 'type' => 'REQ_REPLY'],
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'data' => [
                    'getTicket' => [
                        'ticketId' => '4799638300707885056',
                        'displayId' => '13761',
                        'subject' => 'New starter request: TESTTEST',
                        'status' => 'Open',
                        'priority' => null,
                        'createdTime' => '2026-08-24T12:00:00.000',
                        'updatedTime' => '2026-08-24T12:00:00.000',
                        'requester' => ['userId' => 'req-1'],
                    ],
                ],
            ], 200);
        });

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'superops_user_id' => 'req-1',
        ]);

        $this->actingAs($user)
            ->get(route('support.show', '4799638300707885056'))
            ->assertOk()
            ->assertSee('New starter request: TESTTEST')
            ->assertSee('#13761')
            ->assertSee('Opening details')
            ->assertDontSee('Ticket not found');
    }

    public function test_show_allows_portal_creator_when_superops_requester_does_not_match(): void
    {
        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);

        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'createTicket')) {
                return Http::response([
                    'data' => [
                        'createTicket' => [
                            'ticketId' => 'ticket-created-1',
                            'displayId' => '14001',
                            'subject' => 'TEST FROM APP.ONIT.LTD FOR TOM',
                            'status' => 'Open',
                            'createdTime' => '2026-09-08T11:42:00.000',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response(['data' => ['getTicketConversationList' => []]], 200);
            }

            return Http::response([
                'data' => [
                    'getTicket' => [
                        'ticketId' => 'ticket-created-1',
                        'displayId' => '14001',
                        'subject' => 'TEST FROM APP.ONIT.LTD FOR TOM',
                        'status' => 'Open',
                        'priority' => null,
                        'createdTime' => '2026-09-08T11:42:00.000',
                        'updatedTime' => '2026-09-08T11:42:00.000',
                        'requester' => ['userId' => 'someone-else', 'email' => 'other@example.com'],
                    ],
                ],
            ], 200);
        });

        $client = Client::factory()->create(['superops_account_id' => 'acc-find']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'superops_user_id' => 'req-kris',
            'email' => 'kris@example.com',
        ]);

        app(\App\Services\SuperOps\SuperOpsTicketService::class)
            ->createTicket($user, 'TEST FROM APP.ONIT.LTD FOR TOM', 'Please ignore');

        $this->actingAs($user)
            ->get(route('support.show', 'ticket-created-1'))
            ->assertOk()
            ->assertSee('TEST FROM APP.ONIT.LTD FOR TOM');

        $otherClient = Client::factory()->create(['superops_account_id' => 'acc-other']);
        $outsider = User::factory()->create([
            'client_id' => $otherClient->id,
            'role' => UserRole::ClientAdmin,
            'email' => 'outsider@example.com',
        ]);

        $this->actingAs($outsider)
            ->get(route('support.show', 'ticket-created-1'))
            ->assertForbidden();
    }

    public function test_show_forbids_unrelated_ticket_when_requester_does_not_match(): void
    {
        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);

        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response(['data' => ['getTicketConversationList' => []]], 200);
            }

            return Http::response([
                'data' => [
                    'getTicket' => [
                        'ticketId' => 'ticket-other-1',
                        'displayId' => '99',
                        'subject' => 'Someone else',
                        'status' => 'Open',
                        'priority' => null,
                        'createdTime' => '2026-09-08T11:42:00.000',
                        'updatedTime' => '2026-09-08T11:42:00.000',
                        'requester' => ['userId' => 'not-you', 'email' => 'not-you@example.com'],
                    ],
                ],
            ], 200);
        });

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'superops_user_id' => 'req-1',
            'email' => 'me@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('support.show', 'ticket-other-1'))
            ->assertForbidden();
    }
}
