{{-- SuperOps managed devices — DashboardFeed key: superops --}}
@php($s = $summary)
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="portal-label mb-2">Managed devices</p>
            @if($organisationWide ?? true)
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
        </div>
        @if($s->hasData())
            <a href="{{ route('integrations.superops.launch') }}" class="text-onit hover:text-white text-lg leading-none" title="Open SuperOps">&rarr;</a>
        @endif
    </div>
</x-card>
