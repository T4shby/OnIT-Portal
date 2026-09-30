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
                            ['content' => 'We reset the password', 'time' => '2026-08-24T13:00:00.000', 'type' => 'TECH_REPLY'],
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
            ->assertSee('You replied')
            ->assertSee('A technician replied')
            ->assertSee('We reset the password')
            ->assertSeeInOrder(['Opening details', 'We reset the password'])
            ->assertDontSee('Ticket not found');

        $this->actingAs($user)
            ->getJson(route('support.actions', '4799638300707885056'))
            ->assertOk()
            ->assertJsonPath('actions.0.title', 'You replied')
            ->assertJsonPath('actions.0.text', 'Opening details')
            ->assertJsonPath('actions.1.title', 'A technician replied')
            ->assertJsonPath('actions.1.text', 'We reset the password');
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

        $this->actingAs($outsider)
            ->getJson(route('support.actions', 'ticket-created-1'))
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

    /**
     * Fake SuperOps that returns the ticket's `client` leaf only when the query
     * actually selects it (it is a leaf JSON field; see Brain/ClientAdminDashboard.md).
     */
    private function fakeOlderTicketOnAccount(string $accountId): void
    {
        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);

        Http::fake(function ($request) use ($accountId) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response(['data' => ['getTicketConversationList' => []]], 200);
            }

            $ticket = [
                'ticketId' => 'ticket-old-1',
                'displayId' => '500',
                'subject' => 'Older colleague ticket',
                'status' => 'Open',
                'priority' => null,
                'createdTime' => '2026-01-08T11:42:00.000',
                'updatedTime' => '2026-01-08T11:42:00.000',
                'requester' => ['userId' => 'colleague', 'email' => 'colleague@example.com'],
            ];
            if (preg_match('/getTicket\(input[^}]*\bclient\b/s', $query)) {
                $ticket['client'] = ['accountId' => $accountId, 'name' => 'Acme'];
            }

            return Http::response(['data' => ['getTicket' => $ticket]], 200);
        });
    }

    public function test_staff_and_client_admin_can_open_older_org_tickets_not_created_in_portal(): void
    {
        $this->fakeOlderTicketOnAccount('acc-1');

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $manager->assignedClients()->attach($client);
        $clientAdmin = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin, 'email' => 'boss@example.com']);

        $this->actingAs($manager)->get(route('support.show', 'ticket-old-1'))
            ->assertOk()->assertSee('Older colleague ticket');
        $this->actingAs($clientAdmin)->get(route('support.show', 'ticket-old-1'))
            ->assertOk()->assertSee('Older colleague ticket');

        $unassigned = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $this->actingAs($unassigned)->get(route('support.show', 'ticket-old-1'))->assertForbidden();
    }

    public function test_requester_still_cannot_open_a_colleagues_ticket_by_id(): void
    {
        // Knowing the ticket's real account must not widen personal viewers' access.
        $this->fakeOlderTicketOnAccount('acc-1');

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $requester = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'email' => 'me@example.com']);

        $this->actingAs($requester)->get(route('support.show', 'ticket-old-1'))->assertForbidden();
    }

    /**
     * Fake SuperOps for a ticket created through the portal whose SuperOps requester
     * is not the viewer (the case the "created through this portal" fallback exists for).
     */
    private function fakePortalCreatedTicket(string $ticketId): void
    {
        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
        ]);

        Http::fake(function ($request) use ($ticketId) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'createTicket')) {
                return Http::response(['data' => ['createTicket' => [
                    'ticketId' => $ticketId, 'displayId' => '15001', 'subject' => 'New starter request: Pat Private',
                    'status' => 'Open', 'createdTime' => '2026-09-08T11:42:00.000',
                ]]], 200);
            }

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response(['data' => ['getTicketConversationList' => []]], 200);
            }

            return Http::response(['data' => ['getTicket' => [
                'ticketId' => $ticketId, 'displayId' => '15001', 'subject' => 'New starter request: Pat Private',
                'status' => 'Open', 'priority' => null,
                'createdTime' => '2026-09-08T11:42:00.000', 'updatedTime' => '2026-09-08T11:42:00.000',
                'requester' => ['userId' => 'someone-else', 'email' => 'unmatched@example.com'],
            ]]], 200);
        });
    }

    public function test_requester_cannot_open_a_colleagues_portal_created_ticket_by_id(): void
    {
        $this->fakePortalCreatedTicket('ticket-portal-colleague');

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $creator = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'email' => 'creator@example.com']);
        $colleague = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'email' => 'colleague@example.com']);
        $clientAdmin = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin, 'email' => 'boss@example.com']);

        app(\App\Services\SuperOps\SuperOpsTicketService::class)
            ->createTicket($creator, 'New starter request: Pat Private', 'Salary and start date');

        $this->actingAs($creator)->get(route('support.show', 'ticket-portal-colleague'))->assertOk();
        $this->actingAs($colleague)->get(route('support.show', 'ticket-portal-colleague'))->assertForbidden();
        $this->actingAs($clientAdmin)->get(route('support.show', 'ticket-portal-colleague'))->assertOk();
    }

    public function test_legacy_remembered_account_without_creator_is_not_a_personal_viewer_pass(): void
    {
        $this->fakePortalCreatedTicket('ticket-legacy-1');

        $client = Client::factory()->create(['superops_account_id' => 'acc-1']);
        $requester = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'email' => 'me@example.com']);
        $clientAdmin = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin, 'email' => 'boss@example.com']);

        // Shape written before the creator was recorded: a bare account id.
        \Illuminate\Support\Facades\Cache::put('superops-ticket-account:ticket-legacy-1', 'acc-1', now()->addDay());

        $this->actingAs($requester)->get(route('support.show', 'ticket-legacy-1'))->assertForbidden();
        $this->actingAs($clientAdmin)->get(route('support.show', 'ticket-legacy-1'))->assertOk();
    }
}
