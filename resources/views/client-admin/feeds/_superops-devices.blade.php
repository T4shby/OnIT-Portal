{{-- SuperOps managed devices — DashboardFeed key: superops --}}
@php
    $s = $summary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'superops');
    $mapped = $products->isMapped($client, 'superops');
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="portal-label mb-2">
                @if($orgWide)
                    Managed devices
                @else
                    Your tickets
                @endif
            </p>
            @if($s->hasData())
                @if($orgWide)
                    <p class="text-4xl font-condensed font-bold text-onit">
                        {{ $s->assetsTotal === null ? '-' : number_format($s->assetsTotal) }}
                    </p>
                    <p class="portal-body-muted text-sm mt-2">
                        @if($s->assetsOnline !== null && $s->assetsOffline !== null)
                            {{ number_format($s->assetsOnline) }} online / {{ number_format($s->assetsOffline) }} offline
                        @else
                            Devices in SuperOps
                        @endif
                    </p>
                @else
                    <p class="text-4xl font-condensed font-bold text-onit">
                        {{ $s->openTicketsTotal === null ? '-' : number_format($s->openTicketsTotal) }}
                    </p>
                    <p class="portal-body-muted text-sm mt-2">Your open tickets</p>
                @endif
            @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
                <p class="text-sm portal-body-muted mt-2 leading-relaxed">
                    @if($orgWide)
                        Device figures are not available yet.
                    @else
                        Ticket figures are not available yet.
                    @endif
                </p>
            @else
                @include('client-admin.feeds._unavailable', [
                    'viewerIsTechnician' => $viewerIsTechnician,
                    'unavailableReason' => $s->unavailableReason,
                    'pendingLabel' => $orgWide ? 'Device figures' : 'Ticket figures',
                    'needsAccountManager' => $needsAm || ! $mapped,
                ])
            @endif
        </div>
        @if($s->hasData())
            <a href="{{ route('integrations.superops.launch') }}" class="text-onit hover:text-white text-lg leading-none shrink-0" title="Open SuperOps">&rarr;</a>
        @endif
    </div>
</x-card>
