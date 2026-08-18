<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Huntress\HuntressClientMetricsService;
use App\Services\Huntress\HuntressIncidentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Huntress security cases for the signed-in user’s organisation.
 *
 * Client Admin: all cases. Regular users: only cases involving them.
 */
class HuntressSecurityController extends Controller
{
    public function __construct(
        protected HuntressIncidentService $incidents,
        protected HuntressClientMetricsService $metrics,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $client = $user->client;
        abort_unless($client && $this->incidents->userCanAccessArea($user, $client), 403);

        return $this->renderIndex($request, $client, adminContext: false);
    }

    public function show(Request $request, string $incident): View
    {
        $user = $request->user();
        $client = $user->client;
        abort_unless($client && $this->incidents->userCanAccessArea($user, $client), 403);

        return $this->renderShow($request, $client, $incident, adminContext: false);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        $client = $user->client;
        abort_unless($client && $this->incidents->userCanAccessArea($user, $client), 403);
        // Only people who can see the full org list (admins) may refresh org-wide cache.
        abort_unless($this->incidents->userCanViewAllCases($user, $client), 403);

        $queued = $this->metrics->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('security.huntress.index')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Security cases refresh queued. The list updates when ready.'
                    : 'Please wait before refreshing again.',
            );
    }

    protected function renderIndex(Request $request, Client $client, bool $adminContext): View
    {
        $user = $request->user();
        $filter = (string) $request->query('status', 'all');
        if (! in_array($filter, ['all', 'active', 'resolved'], true)) {
            $filter = 'all';
        }

        // Staff admin: full client list. Portal: filter by role (admin = all, user = own).
        $viewer = $adminContext ? null : $user;
        $list = $this->incidents->listForClient(
            $client,
            $filter === 'all' ? null : $filter,
            $viewer,
        );

        $summary = $this->metrics->summaryForClient($client);
        $canViewAll = $adminContext || $this->incidents->userCanViewAllCases($user, $client);

        // Opening with no cache: staff / org-wide viewers can kick a background pull.
        if ($canViewAll && ! $list->hasData() && ! ($summary->refreshInProgress ?? false)) {
            $this->metrics->queueRefresh($client, respectCooldown: true);
            $summary = $this->metrics->summaryForClient($client);
            $list = $this->incidents->listForClient(
                $client,
                $filter === 'all' ? null : $filter,
                $viewer,
            );
        }

        $orgId = (string) ($client->huntress_organization_id ?? '');
        $consoleBase = rtrim((string) config('services.huntress.console_base_url', ''), '/');
        $huntressConsoleUrl = ($orgId !== '' && $consoleBase !== '')
            ? $consoleBase.'/org/'.$orgId.'/command_center'
            : null;

        return view('security.huntress.index', [
            'client' => $client,
            'list' => $list,
            'summary' => $summary,
            'filter' => $filter,
            'adminContext' => $adminContext,
            'canViewAll' => $canViewAll,
            'canRefresh' => $canViewAll,
            'huntressConsoleUrl' => $adminContext ? $huntressConsoleUrl : null,
            'indexRoute' => $adminContext
                ? route('admin.clients.security.huntress', $client)
                : route('security.huntress.index'),
            'showRouteName' => $adminContext
                ? 'admin.clients.security.huntress.show'
                : 'security.huntress.show',
            'refreshRoute' => $adminContext
                ? route('admin.clients.security.huntress.refresh', $client)
                : route('security.huntress.refresh'),
        ]);
    }

    protected function renderShow(Request $request, Client $client, string $incidentId, bool $adminContext): View
    {
        $user = $request->user();
        $viewer = $adminContext ? null : $user;
        // Admin context: staff already authorized for client - full access to any case for that org.
        if ($adminContext) {
            $case = $this->incidents->findForClient($client, $incidentId, null);
        } else {
            $case = $this->incidents->findForClient($client, $incidentId, $user);
        }
        abort_unless($case !== null, 404);

        return view('security.huntress.show', [
            'client' => $client,
            'case' => $case,
            'adminContext' => $adminContext,
            'indexRoute' => $adminContext
                ? route('admin.clients.security.huntress', $client)
                : route('security.huntress.index'),
        ]);
    }
}
