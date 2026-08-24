<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportTicketRequest;
use App\Services\ActivityLogService;
use App\Services\SuperOps\SuperOpsTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function __construct(
        private SuperOpsTicketService $tickets,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $ticketData = ['tickets' => [], 'listInfo' => []];
        $error = null;

        if ($this->tickets->isAvailable()) {
            try {
                $ticketData = $this->tickets->listTicketsForUser($user, (int) $request->integer('page', 1));
            } catch (\Throwable) {
                $error = 'Unable to load support tickets right now.';
            }
        }

        return view('support.index', [
            'user' => $user,
            'tickets' => collect($ticketData['tickets'] ?? []),
            'apiConfigured' => $this->tickets->isAvailable(),
            'error' => $error,
        ]);
    }

    public function show(Request $request, string $ticketId): View|RedirectResponse
    {
        if (! $this->tickets->isAvailable()) {
            return redirect()->route('support.index')->with('error', 'SuperOps is not configured.');
        }

        try {
            $ticket = $this->tickets->getTicket($ticketId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Support ticket show failed', [
                'ticket_id' => $ticketId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('support.index')->with('error', 'Ticket not found.');
        }

        if (! $ticket || ! $this->tickets->ticketBelongsToUser($ticket, $request->user())) {
            abort(403);
        }

        return view('support.show', compact('ticket'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->user()->client_id) {
            return redirect()->route('support.index')->with('error', 'Support is for client users only.');
        }

        if (! $this->tickets->isAvailable()) {
            return redirect()->route('support.index')->with('error', 'SuperOps is not configured.');
        }

        return view('support.create');
    }

    public function store(StoreSupportTicketRequest $request): RedirectResponse
    {
        try {
            $ticket = $this->tickets->createTicket(
                $request->user(),
                $request->validated('subject'),
                $request->validated('description'),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Support ticket create failed', [
                'client_id' => $request->user()->client_id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Unable to submit support request. Please try again.');
        }

        $this->activityLog->log('support.ticket_created', null, [
            'ticket_id' => $ticket['ticketId'] ?? null,
        ], clientId: $request->user()->client_id);

        return redirect()->route('support.show', $ticket['ticketId'])
            ->with('success', 'Support request submitted.');
    }
}
