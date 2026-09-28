<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityLogService;
use App\Services\Admin\IntegrationHealthService;
use App\Services\Portal\PortalFreshnessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Technician-only Integration refresh health (own admin nav tab).
 */
class IntegrationHealthController extends Controller
{
    public function __construct(
        private IntegrationHealthService $integrationHealth,
        private PortalFreshnessService $freshness,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.integration-health.index', [
            'integrationHealth' => $this->healthOverviewFor($request),
            'freshnessSettings' => $this->freshness->editableValues(),
            'freshnessMeta' => PortalFreshnessService::ADMIN_KEYS,
            'canEditFreshness' => $request->user()->can('update', Setting::class),
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

    public function updateFreshness(Request $request): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        $rules = [
            'freshness' => ['required', 'array'],
            'freshness.hot_minutes' => ['required', 'numeric', 'min:0.5', 'max:240'],
            'freshness.work_idle_minutes' => ['required', 'numeric', 'min:1', 'max:1440'],
            'freshness.off_hours_idle_minutes' => ['required', 'numeric', 'min:1', 'max:1440'],
            'freshness.presence_minutes' => ['required', 'numeric', 'min:1', 'max:240'],
            'freshness.work_start' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'freshness.work_end' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'freshness.timezone' => ['required', 'timezone'],
        ];

        $validated = $request->validate($rules);
        $data = $validated['freshness'];

        $map = [
            'hot_minutes' => 'freshness.hot_minutes',
            'work_idle_minutes' => 'freshness.work_idle_minutes',
            'off_hours_idle_minutes' => 'freshness.off_hours_idle_minutes',
            'presence_minutes' => 'freshness.presence_minutes',
            'work_start' => 'freshness.work_start',
            'work_end' => 'freshness.work_end',
            'timezone' => 'freshness.timezone',
        ];

        foreach ($map as $formKey => $settingKey) {
            $value = $data[$formKey];
            if (in_array($formKey, ['hot_minutes', 'work_idle_minutes', 'off_hours_idle_minutes', 'presence_minutes'], true)) {
                $value = (string) (0 + $value);
            } else {
                $value = trim((string) $value);
                if (in_array($formKey, ['work_start', 'work_end'], true) && preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
                    $value = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
                }
            }
            Setting::set($settingKey, $value);
        }

        Cache::forget('portal.freshness.snapshot.live');
        Cache::forget(PortalFreshnessService::MODE_CACHE_KEY);

        $this->activityLog->log('settings.freshness_updated', null, [
            'freshness' => $data,
        ]);

        return redirect()
            ->route('admin.integration-health.index')
            ->with('success', 'Auto-refresh timing saved. Changes apply on the next schedule minute.');
    }

    /**
     * @return array<string, mixed>
     */
    private function healthOverviewFor(Request $request): array
    {
        $clientIds = $request->user()->accessibleClientIds();

        return $this->integrationHealth->overview(
            $clientIds,
        );
    }
}
