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
}
