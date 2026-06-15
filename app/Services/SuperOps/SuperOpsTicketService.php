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
                'sort' => [['field' => 'updatedTime', 'order' => 'DESC']],
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
                    requester { userId name email }
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
            'status' => 'Open',
            'source' => 'PORTAL',
        ];

        if ($user->superops_user_id) {
            $input['requester'] = ['userId' => $user->superops_user_id];
        }

        $data = $this->api->query(<<<'GQL'
            mutation createTicket($input: CreateTicketInput!) {
                createTicket(input: $input) { ticketId displayId subject status createdTime }
            }
        GQL, ['input' => $input]);

        return $data['createTicket'] ?? [];
    }

    public function ticketBelongsToUser(array $ticket, User $user): bool
    {
        $requesterId = (string) ($ticket['requester']['userId'] ?? '');
        $requesterEmail = strtolower($ticket['requester']['email'] ?? '');

        if ($user->superops_user_id && $requesterId === $user->superops_user_id) {
            return true;
        }

        return $requesterEmail === strtolower($user->email);
    }

    private function requesterCondition(User $user): array
    {
        if ($user->superops_user_id) {
            return ['field' => 'requester.userId', 'operator' => 'eq', 'value' => $user->superops_user_id];
        }

        return ['field' => 'requester.email', 'operator' => 'eq', 'value' => $user->email];
    }
}
