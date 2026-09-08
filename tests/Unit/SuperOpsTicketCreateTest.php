<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsApiClient;
use App\Services\SuperOps\SuperOpsTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SuperOpsTicketCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
            'services.superops.default_request_type' => 'Incident',
        ]);
    }

    public function test_create_ticket_sends_msp_wide_source_and_request_type_for_any_client(): void
    {
        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => [
                    'createTicket' => [
                        'ticketId' => 't-1',
                        'displayId' => '100',
                        'subject' => 'Printer offline',
                        'status' => 'Open',
                        'createdTime' => '2026-08-24T12:00:00.000',
                    ],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['superops_account_id' => 'acc-any']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'superops_user_id' => 'req-any',
        ]);

        $created = app(SuperOpsTicketService::class)->createTicket($user, 'Printer offline', "Line 1\nLine 2");

        $this->assertSame('t-1', $created['ticketId']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $input = $body['variables']['input'] ?? [];

            return ($input['source'] ?? null) === 'INTEGRATION'
                && ($input['subSource'] ?? null) === 'On IT Portal'
                && ($input['status'] ?? null) === 'Open'
                && ($input['requestType'] ?? null) === 'Incident'
                && ($input['description'] ?? null) === 'Line 1<br>'."\n".'Line 2'
                && ($input['client']['accountId'] ?? null) === 'acc-any'
                && ($input['requester']['userId'] ?? null) === 'req-any';
        });
    }

    public function test_create_ticket_uses_configured_request_type(): void
    {
        config(['services.superops.default_request_type' => 'Service Request']);

        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => [
                    'createTicket' => [
                        'ticketId' => 't-2',
                        'displayId' => '101',
                        'subject' => 'New starter request: Alex',
                        'status' => 'Open',
                    ],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['superops_account_id' => 'acc-2']);
        $user = User::factory()->create(['client_id' => $client->id]);

        app(SuperOpsTicketService::class)->createTicket($user, 'New starter request: Alex', 'Body');

        Http::assertSent(function ($request) use ($user) {
            $input = $request->data()['variables']['input'] ?? [];

            return ($input['requestType'] ?? null) === 'Service Request'
                && ($input['requester']['email'] ?? null) === $user->email;
        });
    }

    public function test_api_client_throws_on_empty_graphql_message_with_client_error(): void
    {
        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => ['createTicket' => null],
                'errors' => [['message' => '']],
                'extensions' => [
                    'clientError' => [
                        ['code' => 'mandatory_validation_failed', 'param' => ['attributes' => ['requestType']]],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mandatory_validation_failed');

        app(SuperOpsApiClient::class)->query('mutation { createTicket }', ['input' => []]);
    }

    public function test_create_ticket_throws_when_superops_omits_ticket_id(): void
    {
        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => ['createTicket' => null],
            ], 200),
        ]);

        $client = Client::factory()->create(['superops_account_id' => 'acc-3']);
        $user = User::factory()->create(['client_id' => $client->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a ticket id');

        app(SuperOpsTicketService::class)->createTicket($user, 'Subject', 'Body');
    }

    public function test_get_ticket_does_not_select_description_and_loads_opening_from_conversations(): void
    {
        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'getTicketConversationList')) {
                return Http::response([
                    'data' => [
                        'getTicketConversationList' => [
                            [
                                'content' => 'NEW STARTER REQUEST (submitted via On IT Portal)',
                                'time' => '2026-08-24T12:00:00.000',
                                'type' => 'REQ_REPLY',
                            ],
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
                        'requester' => ['userId' => 'req-any', 'email' => 'jane@example.com'],
                    ],
                ],
            ], 200);
        });

        $ticket = app(SuperOpsTicketService::class)->getTicket('4799638300707885056');

        $this->assertSame('13761', $ticket['displayId']);
        $this->assertSame('NEW STARTER REQUEST (submitted via On IT Portal)', $ticket['description']);

        Http::assertSent(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (! str_contains($query, 'query getTicket(')) {
                return false;
            }

            return ! preg_match('/\bdescription\b/', $query);
        });
    }
}
