<?php

namespace App\Http\Controllers;

use App\Services\Portal\ClientHomeOverviewService;
use App\Services\Portal\ClientVisibilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monthly / service review presentation for Client Admins (and org-wide viewers).
 *
 * Several mockup metrics are explicitly marked pipeline/setup - never omitted silently.
 */
class ClientReportsController extends Controller
{
    public function __construct(
        private ClientHomeOverviewService $homeOverview,
        private ClientVisibilityService $visibility,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);

        // Reports are organisation-level value story.
        abort_unless($this->visibility->canViewOrganisationWide($user, $client), 403);

        $period = $request->query('period') === 'last' ? 'last' : 'this';
        $overview = $this->homeOverview->forUser($user, $period);

        return view('reports.index', [
            'user' => $user,
            'client' => $client,
            'overview' => $overview,
        ]);
    }
}
