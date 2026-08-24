<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNewStarterRequest;
use App\Services\ActivityLogService;
use App\Services\Support\NewStarterTicketService;
use App\Services\SuperOps\SuperOpsTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactSupportController extends Controller
{
    public function __construct(
        private SuperOpsTicketService $tickets,
        private NewStarterTicketService $newStarters,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('contact-support'), 403);

        return view('contact-support.index', [
            'user' => $user,
            'contact' => config('onit_support'),
            'apiConfigured' => $this->tickets->isAvailable(),
            'clientLinked' => filled($user->client?->superops_account_id),
        ]);
    }

    public function createNewStarter(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('contact-support'), 403);

        if (! $this->tickets->isAvailable()) {
            return redirect()
                ->route('contact-support.index')
                ->with('error', 'Online requests are unavailable right now. Please call or email the Service Desk.');
        }

        if (! filled($user->client?->superops_account_id)) {
            return redirect()
                ->route('contact-support.index')
                ->with('error', 'Your organisation is not linked for online tickets yet. Please call or email the Service Desk.');
        }

        return view('contact-support.new-starter', [
            'user' => $user,
            'contact' => config('onit_support'),
        ]);
    }

    public function storeNewStarter(StoreNewStarterRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('contact-support'), 403);

        try {
            $ticket = $this->newStarters->submit($user, $request->validated());
        } catch (\Throwable) {
            return back()->withInput()->with(
                'error',
                'Unable to submit the new starter request. Please try again or call the Service Desk.',
            );
        }

        $this->activityLog->log('support.new_starter_requested', null, [
            'ticket_id' => $ticket['ticketId'] ?? null,
            'starter_name' => $request->validated('starter_name'),
        ], clientId: $user->client_id);

        $ticketId = $ticket['ticketId'] ?? null;

        if ($ticketId) {
            return redirect()
                ->route('support.show', $ticketId)
                ->with('success', 'New starter request submitted to the Service Desk.');
        }

        return redirect()
            ->route('contact-support.index')
            ->with('success', 'New starter request submitted to the Service Desk.');
    }
}
