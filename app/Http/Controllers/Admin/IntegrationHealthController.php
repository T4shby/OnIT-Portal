<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Technician-only Integration refresh health (own admin nav tab).
 */
class IntegrationHealthController extends Controller
{
    public function __construct(
        private IntegrationHealthService $integrationHealth,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.integration-health.index', [
            'integrationHealth' => $this->healthOverviewFor($request),
        ]);
    }

    /**
     * HTML fragment for live polling (every ~5s on the Integration Health tab).
     */
    public function live(Request $request): View
    {
        return view('admin.partials.integration-health', [
            'integrationHealth' => $this->healthOverviewFor($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function healthOverviewFor(Request $request): array
    {
        $clientIds = $request->user()->accessibleClientIds();

        return $this->integrationHealth->overview(
            empty($clientIds) ? null : $clientIds,
        );
    }
}
