<?php

namespace App\Http\Controllers;

use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Portal\ClientProductService;
use App\Services\Portal\ClientVisibilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Organisation online backups (mailboxes, OneDrive, SharePoint) for Client Admins.
 */
class ClientBackupsController extends Controller
{
    public function __construct(
        private DropsuiteClientMetricsService $metrics,
        private ClientVisibilityService $visibility,
        private ClientProductService $products,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewOrganisationWide() && filled($user->client_id), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);
        abort_unless($this->products->isLive($client, 'dropsuite') || $this->products->isMapped($client, 'dropsuite'), 404);

        $summary = $this->metrics->summaryForClient($client, manualRefresh: false, viewer: $user);

        return view('client-admin.backups', [
            'client' => $client,
            'summary' => $summary,
        ]);
    }
}
