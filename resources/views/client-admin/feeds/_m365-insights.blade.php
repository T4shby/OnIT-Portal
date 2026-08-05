{{-- M365 licences tile — DashboardFeed key: m365_insights --}}
@php($m = $m365Insights)
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="portal-label mb-2">Microsoft 365</p>
            @if($m->hasData())
                <p class="text-4xl font-condensed font-bold text-onit">
                    {{ $m->overallUtilizationPct === null ? '-' : number_format($m->overallUtilizationPct, 0).'%' }}
                </p>
                <p class="portal-body-muted text-sm mt-2">
                    Licence utilisation
                    @if($m->licensedUserCount !== null)
                        / {{ number_format($m->licensedUserCount) }} users
                    @endif
                </p>
            @else
                <p class="text-sm portal-body-muted mt-2">
                    @if($viewerIsTechnician ?? false)
                        {{ $m->unavailableReason ?? 'Not available yet.' }}
                    @else
                        Licence figures are not available yet.
                    @endif
                </p>
            @endif
        </div>
        @can('view-m365-directory')
            <a href="{{ route('microsoft-365.directory') }}" class="text-onit hover:text-white text-lg leading-none" title="Microsoft 365 directory">&rarr;</a>
        @endcan
    </div>
</x-card>
