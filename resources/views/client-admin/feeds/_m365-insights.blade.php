{{-- M365 licences tile — DashboardFeed key: m365_insights --}}
@php
    $m = $m365Insights;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'm365');
    $mapped = $products->isMapped($client, 'm365');
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="portal-label mb-2">Microsoft 365</p>
            @if($m->hasData())
                <p class="text-4xl font-condensed font-bold text-onit">
                    {{ $m->overallUtilizationPct === null ? '-' : number_format($m->overallUtilizationPct, 0).'%' }}
                </p>
                <p class="portal-body-muted text-sm mt-2">
                    Paid licence utilisation
                    @if($m->licensedUserCount !== null)
                        / {{ number_format($m->licensedUserCount) }} licensed users
                    @endif
                </p>
                <p class="portal-body-muted text-xs mt-1">User mailboxes only — not shared mailboxes</p>
            @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
                <p class="text-sm portal-body-muted mt-2 leading-relaxed">Licence figures are not available yet.</p>
            @else
                @include('client-admin.feeds._unavailable', [
                    'viewerIsTechnician' => $viewerIsTechnician,
                    'unavailableReason' => $m->unavailableReason ?? 'Microsoft 365 is not connected for this organisation.',
                    'pendingLabel' => 'Licence figures',
                    'needsAccountManager' => $needsAm || ! $mapped,
                ])
            @endif
        </div>
        @can('view-m365-directory')
            <a href="{{ route('microsoft-365.directory') }}" class="text-onit hover:text-white text-lg leading-none shrink-0" title="Microsoft 365 directory">&rarr;</a>
        @endcan
    </div>
</x-card>
