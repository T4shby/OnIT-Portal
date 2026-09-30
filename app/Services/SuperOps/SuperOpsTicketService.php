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
                    client
                }
            }
        GQL, ['input' => ['ticketId' => $ticketId]]);

        $ticket = $data['getTicket'] ?? null;

        if (! is_array($ticket)) {
            return null;
        }

        $conversations = $this->conversationRows($ticketId);
        $ticket['conversations'] = $conversations;
        $opening = $conversations[0]['content'] ?? null;
        if (is_string($opening) && $opening !== '') {
            $ticket['description'] = $opening;
        }

        return $ticket;
    }

    /**
     * @param  bool  $descriptionIsHtml  true only for HTML the portal built itself with every
     *                                   value escaped (e.g. NewStarterTicketService). User-typed
     *                                   text must use the default so it is escaped.
     */
    public function createTicket(User $user, string $subject, string $description, bool $descriptionIsHtml = false): array
    {
        $accountId = $user->client?->superops_account_id;

        if (! $accountId) {
            throw new \RuntimeException('This client is not linked to a SuperOps account.');
        }

        $input = [
            'subject' => $subject,
            'description' => $descriptionIsHtml ? trim($description) : SuperOpsHtml::fromPlainText($description),
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

        $this->rememberTicketAccount((string) $created['ticketId'], $accountId, $user->id);

        return $created;
    }

    public function userCanViewTicket(array $ticket, User $user): bool
    {
        if ($this->ticketBelongsToUser($ticket, $user)) {
            return true;
        }

        $ticketId = (string) ($ticket['ticketId'] ?? '');
        // `client` is now selected by getTicket(); previously it never was, so this
        // was always '' and only the 14-day "remembered" portal-created path worked.
        $ticketAccount = $this->ticketAccountId($ticket);
        $rememberedEntry = $ticketId !== '' ? $this->rememberedTicket($ticketId) : null;
        $remembered = $rememberedEntry['account'] ?? '';
        if ($ticketAccount !== '' && $remembered !== '' && $ticketAccount !== $remembered) {
            // SuperOps is the source of truth if the ticket was moved to another client.
            $remembered = '';
        }
        $accountId = $ticketAccount !== '' ? $ticketAccount : $remembered;

        if ($accountId === '') {
            return false;
        }

        if ($user->canUseClientSupport() && filled($user->client?->superops_account_id)) {
            // Org-wide viewers (client admins) may open any ticket on their SuperOps
            // account. Personal viewers only get the "created through this portal"
            // fallback (for when SuperOps did not record them as requester) on tickets
            // *they* created - not a colleague's, e.g. a new-starter request.
            $orgWide = app(\App\Services\Portal\ClientVisibilityService::class)
                ->canViewOrganisationWide($user, $user->client);
            $createdByViewer = ($rememberedEntry['user_id'] ?? null) === $user->id;
            $candidate = $orgWide ? $accountId : ($createdByViewer ? $remembered : '');

            if ($candidate !== '' && (string) $user->client->superops_account_id === $candidate) {
                return true;
            }
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
     * Public replies only (`getTicketConversationList`). Internal notes are
     * `getTicketNoteList` and are not loaded. Failure must not hide the ticket.
     *
     * @return list<array{at: ?string, type: string, content: string}>
     */
    private function conversationRows(string $ticketId): array
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
            return [];
        }

        $list = $data['getTicketConversationList'] ?? [];

        if (is_array($list) && isset($list['content']) && ! array_is_list($list)) {
            $list = [$list];
        }

        if (! is_array($list)) {
            return [];
        }

        $rows = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $rows[] = [
                'at' => is_string($row['time'] ?? null) && $row['time'] !== '' ? $row['time'] : null,
                'type' => strtoupper(trim((string) ($row['type'] ?? ''))),
                'content' => $content,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        });

        return $rows;
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

    private function rememberTicketAccount(string $ticketId, string $accountId, int $creatorUserId): void
    {
        Cache::put($this->ticketAccountCacheKey($ticketId), [
            'account' => $accountId,
            'user_id' => $creatorUserId,
        ], now()->addDays(14));
    }

    /**
     * @return array{account: string, user_id: ?int}|null
     */
    private function rememberedTicket(string $ticketId): ?array
    {
        $value = Cache::get($this->ticketAccountCacheKey($ticketId));

        // Entries written before the creator was recorded are a bare account id:
        // still usable by org-wide viewers and staff, never by personal viewers.
        if (is_string($value) && $value !== '') {
            return ['account' => $value, 'user_id' => null];
        }

        if (is_array($value) && is_string($value['account'] ?? null) && $value['account'] !== '') {
            return [
                'account' => $value['account'],
                'user_id' => isset($value['user_id']) ? (int) $value['user_id'] : null,
            ];
        }

        return null;
    }

    private function ticketAccountCacheKey(string $ticketId): string
    {
        return 'superops-ticket-account:'.$ticketId;
    }
}
