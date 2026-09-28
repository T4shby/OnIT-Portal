<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PortalLinkType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePortalLinkRequest;
use App\Http\Requests\Admin\UpdatePortalLinkRequest;
use App\Models\Client;
use App\Models\PortalLink;
use App\Services\ActivityLogService;
use App\Services\ExternalServicesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalLinkController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
        private ExternalServicesService $externalServices,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PortalLink::class);

        $clientIds = $request->user()->accessibleClientIds();

        $links = PortalLink::query()
            ->with('client')
            ->where(function ($q) use ($clientIds) {
                $q->whereNull('client_id')->orWhereIn('client_id', $clientIds);
            })
            ->orderBy('display_order')
            ->paginate(15);

        return view('admin.portal-links.index', compact('links'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PortalLink::class);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->orderBy('name')
            ->get();

        $roles = UserRole::cases();
        $linkTypes = PortalLinkType::cases();

        return view('admin.portal-links.create', compact('clients', 'roles', 'linkTypes'));
    }

    public function store(StorePortalLinkRequest $request): RedirectResponse
    {
        $this->authorize('create', PortalLink::class);

        $link = PortalLink::create($this->prepareLinkData($request->validated()));

        $this->externalServices->clearCache($link->client_id);
        $this->activityLog->log('portal_link.created', $link, clientId: $link->client_id);

        return redirect()->route('admin.portal-links.index')
            ->with('success', 'Portal link created successfully.');
    }

    public function edit(Request $request, PortalLink $portalLink): View
    {
        $this->authorize('update', $portalLink);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->orderBy('name')
            ->get();

        $roles = UserRole::cases();
        $linkTypes = PortalLinkType::cases();

        return view('admin.portal-links.edit', compact('portalLink', 'clients', 'roles', 'linkTypes'));
    }

    public function update(UpdatePortalLinkRequest $request, PortalLink $portalLink): RedirectResponse
    {
        $this->authorize('update', $portalLink);

        $portalLink->update($this->prepareLinkData($request->validated()));

        $this->externalServices->clearCache($portalLink->client_id);
        $this->activityLog->log('portal_link.updated', $portalLink, clientId: $portalLink->client_id);

        return redirect()->route('admin.portal-links.index')
            ->with('success', 'Portal link updated successfully.');
    }

    public function destroy(PortalLink $portalLink): RedirectResponse
    {
        $this->authorize('delete', $portalLink);

        $this->activityLog->log('portal_link.deleted', $portalLink, clientId: $portalLink->client_id);

        $clientId = $portalLink->client_id;
        $portalLink->delete();

        $this->externalServices->clearCache($clientId);

        return redirect()->route('admin.portal-links.index')
            ->with('success', 'Portal link deleted successfully.');
    }

    private function prepareLinkData(array $data): array
    {
        $type = PortalLinkType::from($data['link_type'] ?? PortalLinkType::External->value);

        if ($type !== PortalLinkType::External) {
            $data['url'] = PortalLink::urlForType($type);
        }

        return $data;
    }
}
