<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\Dropsuite\DropsuiteOrganizationDirectory;
use App\Services\Portal\ClientProductService;
use App\Services\Portal\DashboardFeedRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DropsuiteOrganizationController extends Controller
{
    public function __construct(
        private DropsuiteOrganizationDirectory $directory,
        private ClientProductService $products,
        private ActivityLogService $activityLog,
        private DashboardFeedRegistry $feeds,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        $filter = (string) $request->query('filter', DropsuiteOrganizationDirectory::FILTER_LIVE);
        if (! in_array($filter, [
            DropsuiteOrganizationDirectory::FILTER_LIVE,
            DropsuiteOrganizationDirectory::FILTER_UNMAPPED,
            DropsuiteOrganizationDirectory::FILTER_ALL,
        ], true)) {
            $filter = DropsuiteOrganizationDirectory::FILTER_LIVE;
        }

        $fresh = $request->boolean('refresh');
        $rows = [];
        $error = null;

        if (! $this->directory->isConfigured()) {
            $error = 'Dropsuite is not configured (DROPSUITE_ENABLED plus URL and tokens).';
        } else {
            try {
                $rows = $this->directory->catalog($request->user(), $filter, $fresh);
            } catch (Throwable $e) {
                $error = 'Dropsuite list failed. Try Refresh, or check Integration Health.';
                report($e);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($needle): bool {
                    $hay = strtolower($row['name'].' '.$row['organization_id'].' '.($row['mapped_client']?->name ?? '').' '.($row['suggested_client']?->name ?? ''));

                    return str_contains($hay, $needle);
                },
            ));
        }

        return view('admin.clients.dropsuite-organizations', [
            'rows' => $rows,
            'filter' => $filter,
            'search' => $search,
            'error' => $error,
            'assignableClients' => $this->directory->assignableClients($request->user()),
            'unmappedLiveClients' => $this->directory->unmappedLiveClients($request->user()),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Client::class);

        $data = $request->validate([
            'organization_id' => ['required', 'string', 'max:64'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $organizationId = trim($data['organization_id']);
        if ($organizationId === '' || str_contains($organizationId, '-')) {
            return back()->with('error', 'Use the short organization_id from this list, not the Dropsuite user id (360787-12).');
        }

        $client = Client::query()->findOrFail((int) $data['client_id']);
        $this->authorize('update', $client);

        $taken = Client::query()
            ->whereRaw('LOWER(dropsuite_organization_id) = ?', [strtolower($organizationId)])
            ->whereKeyNot($client->getKey())
            ->first();

        if ($taken !== null) {
            return back()->with('error', 'That Dropsuite organisation is already linked to '.$taken->name.'.');
        }

        $client->update(['dropsuite_organization_id' => $organizationId]);
        $this->products->syncEntitlementsFromMappings($client->fresh());
        $this->activityLog->log('client.updated', $client, clientId: $client->id);
        $this->feeds->queueMissingSnapshots($client->fresh());

        return redirect()
            ->route('admin.clients.dropsuite-organizations', [
                'filter' => $request->input('filter', DropsuiteOrganizationDirectory::FILTER_LIVE),
                'q' => $request->input('q'),
            ])
            ->with('success', $client->name.' mapped to Dropsuite organisation '.$organizationId.'.');
    }
}
