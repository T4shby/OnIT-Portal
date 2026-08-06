<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\HuntressSecurityController;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff view of a client’s Huntress security dashboard + cases.
 * Same access model as View Microsoft 365 directory (policy: view client).
 */
class ClientHuntressSecurityController extends HuntressSecurityController
{
    public function showForClient(Request $request, Client $client): View
    {
        $this->authorize('view', $client);
        abort_unless($this->incidents->isAvailableForClient($client), 404);

        return $this->renderIndex($request, $client, adminContext: true);
    }

    public function incidentForClient(Request $request, Client $client, string $incident): View
    {
        $this->authorize('view', $client);
        abort_unless($this->incidents->isAvailableForClient($client), 404);

        return $this->renderShow($request, $client, $incident, adminContext: true);
    }

    public function refreshForClient(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('view', $client);
        abort_unless($this->incidents->isAvailableForClient($client), 404);

        $queued = $this->metrics->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('admin.clients.security.huntress', $client)
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Huntress refresh queued. Metrics and cases update when ready.'
                    : 'Please wait before refreshing again.',
            );
    }
}
