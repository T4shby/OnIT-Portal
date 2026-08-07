<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff view of a client’s Dropsuite backup metrics (org-wide).
 */
class ClientDropsuiteBackupController extends Controller
{
    public function __construct(private DropsuiteClientMetricsService $metrics) {}

    public function show(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        $summary = $this->metrics->summaryForClient($client, manualRefresh: false, viewer: $request->user());

        return view('admin.clients.dropsuite', [
            'client' => $client,
            'summary' => $summary,
        ]);
    }

    public function refresh(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('view', $client);

        $queued = $this->metrics->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('admin.clients.dropsuite', $client)
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Dropsuite refresh queued on the high queue. Figures update when a worker finishes the job.'
                    : 'Please wait before refreshing again, or check Integration Health (Never loaded / platform disabled).',
            );
    }
}
