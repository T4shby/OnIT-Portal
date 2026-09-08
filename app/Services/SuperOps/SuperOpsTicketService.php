<?php

namespace App\Services\SuperOps;

use App\Models\Client;
use App\Models\User;
use App\Support\SuperOpsHtml;
use Illuminate\Support\Facades\Cache;

class SuperOpsTicketService
{
    public function __construct(private SuperOpsApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function listTicketsForUser(User $user, int $page = 1): array
    {
        $data = $this->api->query(<<<'GQL'
            query getTicketList($input: ListInfoInput!) {
                getTicketList(input: $input) {
                    tickets { ticketId displayId subject status priority createdTime updatedTime }
                    listInfo { totalCount page pageSize }
                }
            }
        GQL, [
            'input' => array_filter([
                'page' => $page,
                'pageSize' => 20,
                'condition' => $this->requesterCondition($user),
                'sort' => [['attribute' => 'updatedTime', 'order' => 'DESC']],
            ]),
        ]);

        return $data['getTicketList'] ?? ['tickets' => [], 'listInfo' => []];
    }

    public function getTicket(string $ticketId): ?array
    {
        $data = $this->api->query(<<<'GQL'
            query getTicket($input: TicketIdentifierInput!) {
                getTicket(input: $input) {
                    ticketId displayId subject status priority
                    createdTime updatedTime
                    requester
                }
            }
        GQL, ['input' => ['ticketId' => $ticketId]]);

        $ticket = $data['getTicket'] ?? null;

        if (! is_array($ticket)) {
            return null;
        }

        $opening = $this->openingDescription($ticketId);
        if ($opening !== null) {
            $ticket['description'] = $opening;
        }

        return $ticket;
    }

    public function createTicket(User $user, string $subject, string $description): array
    {
        $accountId = $user->client?->superops_account_id;

        if (! $accountId) {
            throw new \RuntimeException('This client is not linked to a SuperOps account.');
        }

        $input = [
            'subject' => $subject,
            'description' => SuperOpsHtml::fromPlainText($description),
            'client' => ['accountId' => $accountId],
            // SuperOps TicketSource enum: FORM | AGENT | EMAIL | AI | PHONE | INTEGRATION
            // PORTAL is invalid and returns a GraphQL Internal Server Error.
            'source' => 'INTEGRATION',
            'subSource' => 'On IT Portal',
            'status' => 'Open',
            // MSP-wide: SuperOps CreateTicketInput. See Brain/SuperOpsIntegration.md
            // (createTicket contract). requestType is mandatory on this MSP even
            // though vendor docs mark it optional.
            'requestType' => (string) config('services.superops.default_request_type', 'Incident'),
        ];

        if ($user->superops_user_id) {
            $input['requester'] = ['userId' => $user->superops_user_id];
        } elseif (filled($user->email)) {
            $input['requester'] = ['email' => $user->email];
        }

        $data = $this->api->query(<<<'GQL'
            mutation createTicket($input: CreateTicketInput!) {
                createTicket(input: $input) { ticketId displayId subject status createdTime }
            }
        GQL, ['input' => $input]);

        $created = $data['createTicket'] ?? null;

        if (! is_array($created) || empty($created['ticketId'])) {
            throw new \RuntimeException('SuperOps did not return a ticket id.');
        }

        $this->rememberTicketAccount((string) $created['ticketId'], $accountId);

        return $created;
    }

    public function userCanViewTicket(array $ticket, User $user): bool
    {
        if ($this->ticketBelongsToUser($ticket, $user)) {
            return true;
        }

        $ticketId = (string) ($ticket['ticketId'] ?? '');
        $accountId = $this->ticketAccountId($ticket);
        if ($accountId === '' && $ticketId !== '') {
            $accountId = $this->rememberedTicketAccount($ticketId) ?? '';
        }

        if ($accountId === '') {
            return false;
        }

        if ($user->canUseClientSupport() && filled($user->client?->superops_account_id)
            && (string) $user->client->superops_account_id === $accountId) {
            return true;
        }

        if ($user->isTeamMember()) {
            $client = Client::query()->where('superops_account_id', $accountId)->first();

            return $client !== null && $user->canAccessClient($client->id);
        }

        return false;
    }

    public function ticketBelongsToUser(array $ticket, User $user): bool
    {
        $requester = $this->normalizeJsonObject($ticket['requester'] ?? null);
        $requesterId = (string) ($requester['userId'] ?? $requester['user_id'] ?? $requester['id'] ?? '');
        $requesterEmail = strtolower((string) ($requester['email'] ?? $requester['userEmail'] ?? ''));

        if ($user->superops_user_id && $requesterId !== '' && $requesterId === $user->superops_user_id) {
            return true;
        }

        if ($requesterEmail !== '' && $requesterEmail === strtolower((string) $user->email)) {
            return true;
        }

        return false;
    }

    /**
     * SuperOps Ticket type has no `description` field. Opening text lives on
     * the conversation list. Failure here must not hide the ticket.
     */
    private function openingDescription(string $ticketId): ?string
    {
        try {
            $data = $this->api->query(<<<'GQL'
                query getTicketConversationList($input: TicketIdentifierInput!) {
                    getTicketConversationList(input: $input) {
                        content
                        time
                        type
                    }
                }
            GQL, ['input' => ['ticketId' => $ticketId]]);
        } catch (\Throwable) {
            return null;
        }

        $list = $data['getTicketConversationList'] ?? [];

        if (is_array($list) && isset($list['content']) && ! array_is_list($list)) {
            $list = [$list];
        }

        if (! is_array($list)) {
            return null;
        }

        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return null;
    }

    private function requesterCondition(User $user): array
    {
        // Live SuperOps RuleConditionInput uses attribute/operator/value with operator "is".
        if ($user->superops_user_id) {
            return [
                'attribute' => 'requester.userId',
                'operator' => 'is',
                'value' => $user->superops_user_id,
            ];
        }

        return [
            'attribute' => 'requester.email',
            'operator' => 'is',
            'value' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function ticketAccountId(array $ticket): string
    {
        $client = $this->normalizeJsonObject($ticket['client'] ?? null);

        return (string) ($client['accountId'] ?? $client['account_id'] ?? '');
    }

    private function rememberTicketAccount(string $ticketId, string $accountId): void
    {
        Cache::put($this->ticketAccountCacheKey($ticketId), $accountId, now()->addDays(14));
    }

    private function rememberedTicketAccount(string $ticketId): ?string
    {
        $value = Cache::get($this->ticketAccountCacheKey($ticketId));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function ticketAccountCacheKey(string $ticketId): string
    {
        return 'superops-ticket-account:'.$ticketId;
    }
}
