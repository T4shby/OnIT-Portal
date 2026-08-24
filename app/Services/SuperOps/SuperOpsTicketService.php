<?php

namespace App\Services\SuperOps;

use App\Models\User;

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
                    ticketId displayId subject description status priority
                    createdTime updatedTime
                    requester
                }
            }
        GQL, ['input' => ['ticketId' => $ticketId]]);

        return $data['getTicket'] ?? null;
    }

    public function createTicket(User $user, string $subject, string $description): array
    {
        $accountId = $user->client?->superops_account_id;

        if (! $accountId) {
            throw new \RuntimeException('This client is not linked to a SuperOps account.');
        }

        $input = [
            'subject' => $subject,
            'description' => $description,
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

        return $created;
    }

    public function ticketBelongsToUser(array $ticket, User $user): bool
    {
        $requester = $this->normalizeJsonObject($ticket['requester'] ?? null);
        $requesterId = (string) ($requester['userId'] ?? $requester['user_id'] ?? '');
        $requesterEmail = strtolower((string) ($requester['email'] ?? ''));

        if ($user->superops_user_id && $requesterId === $user->superops_user_id) {
            return true;
        }

        return $requesterEmail === strtolower($user->email);
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
}
