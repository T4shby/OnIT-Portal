<?php

namespace App\Http\Controllers;

use App\Services\ExternalServicesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private ExternalServicesService $externalServices,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $portalLinks = $this->externalServices->getLinksForUser($user);

        return view('dashboard.index', compact('user', 'portalLinks'));
    }
}
