<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function __construct(
        private IntegrationHealthService $integrationHealth,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $clientIds = $user->accessibleClientIds();

        $stats = [
            'clients' => Client::whereIn('id', $clientIds)->count(),
            'users' => User::whereIn('client_id', $clientIds)->count(),
        ];

        // Super admins also see staff-side (client_id NULL) events - see ActivityLogController.
        $recentActivity = ActivityLog::with('user')
            ->unless($user->can('manage-all-clients'), fn ($q) => $q->whereIn('client_id', $clientIds))
            ->latest()
            ->limit(10)
            ->get();

        // null = unscoped (super admin: every client, plus client-less queue rows).
        $healthScope = $user->can('manage-all-clients') ? null : $clientIds;

        $overview = $this->integrationHealth->overview(
            $healthScope,
        );

        $healthSummary = [
            'pending' => $overview['queue']['pending'] ?? 0,
            'stuck' => $overview['stuck_count'] ?? 0,
            'due' => $overview['due_count'] ?? 0,
            'aging' => $overview['aging_count'] ?? 0,
            'cold' => $overview['cold_count'] ?? 0,
        ];

        $coverageContext = $this->coverageForRequest($request, $user, $healthScope, $overview);

        return view('admin.dashboard', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'healthSummary' => $healthSummary,
            'productCoverage' => $coverageContext['coverage'],
            'coverageAccountManagers' => $coverageContext['account_managers'],
            'coverageAccountManagerFilter' => $coverageContext['filter'],
            'coverageFilterActive' => $coverageContext['filter_active'],
        ]);
    }

    public function exportProductCoverage(Request $request): StreamedResponse
    {
        $user = $request->user();
        $clientIds = $user->accessibleClientIds();
        $healthScope = $user->can('manage-all-clients') ? null : $clientIds;
        $overview = $this->integrationHealth->overview($healthScope);
        $coverageContext = $this->coverageForRequest($request, $user, $healthScope, $overview);
        $csv = $this->integrationHealth->productCoverageCsv($coverageContext['coverage']);

        $suffix = match ($coverageContext['filter']) {
            'none' => '-unassigned',
            default => is_numeric($coverageContext['filter'] ?? null)
                ? '-am-'.(int) $coverageContext['filter']
                : '',
        };
        $filename = 'sold-product-coverage-'.now()->timezone('Europe/London')->format('Y-m-d').$suffix.'.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<int>|null  $healthScope
     * @param  array<string, mixed>  $overview
     * @return array{
     *   coverage: array<string, mixed>,
     *   account_managers: \Illuminate\Support\Collection<int, User>,
     *   filter: int|string|null,
     *   filter_active: bool
     * }
     */
    private function coverageForRequest(
        Request $request,
        User $user,
        ?array $healthScope,
        array $overview,
    ): array {
        $coverage = $this->integrationHealth->productCoverage($healthScope, $overview);
        $accountManagers = collect();
        $filter = null;
        $filterActive = false;

        if ($user->can('manage-all-clients')) {
            $accountManagers = User::query()
                ->where('role', UserRole::AccountManager)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);

            $raw = $request->query('account_manager');
            if ($raw === 'none') {
                $filter = 'none';
                $filterActive = true;
                $coverage = $this->integrationHealth->filterCoverageWithoutAccountManager($coverage);
            } elseif (filled($raw) && ctype_digit((string) $raw)) {
                $amId = (int) $raw;
                $am = $accountManagers->firstWhere('id', $amId);
                if ($am instanceof User) {
                    $filter = $amId;
                    $filterActive = true;
                    $ids = $am->assignedClients()->pluck('clients.id')->map(fn ($id) => (int) $id)->all();
                    $coverage = $this->integrationHealth->filterCoverageByClientIds($coverage, $ids);
                }
            }
        }

        return [
            'coverage' => $coverage,
            'account_managers' => $accountManagers,
            'filter' => $filter,
            'filter_active' => $filterActive,
        ];
    }
}
