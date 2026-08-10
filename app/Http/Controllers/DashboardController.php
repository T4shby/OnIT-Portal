<?php

namespace App\Http\Controllers;

use App\Services\ExternalServicesService;
use App\Services\Portal\ClientHomeOverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private ExternalServicesService $externalServices,
        private ClientHomeOverviewService $homeOverview,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $portalLinks = $this->externalServices->getLinksForUser($user);
        $overview = $this->homeOverview->forUser($user);

        // Client Admin + requesters with systems: new glance dashboard.
        // Team without a bound client keeps the simple home with portals.
        $useGlance = $user->canViewClientAdminDashboard() && $user->client_id;

        if ($useGlance) {
            return view('dashboard.glance', [
                'user' => $user,
                'portalLinks' => $portalLinks,
                'overview' => $overview,
            ]);
        }

        return view('dashboard.index', compact('user', 'portalLinks', 'overview'));
    }
}
