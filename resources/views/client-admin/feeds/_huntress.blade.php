{{-- Huntress security — DashboardFeed key: huntress --}}
@php
    $h = $huntressSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="portal-label mb-2">Security (Huntress)</p>
            @if($h->hasData())
                @php
                    $openIncidents = $h->openIncidents;
                    $isolated = $h->edrIsolatedAgents;
                    $attention = ($openIncidents ?? 0) > 0 || ($isolated ?? 0) > 0;
                @endphp
                <p class="text-4xl font-condensed font-bold {{ $attention ? 'text-amber-300' : 'text-white' }}">
                    {{ $openIncidents === null ? '-' : number_format($openIncidents) }}
                </p>
                <p class="portal-body-muted text-sm mt-2 leading-relaxed">Open incidents</p>
                <p class="portal-body-muted text-xs mt-3 leading-relaxed">
                    Agents {{ $h->agentsTotal === null ? '—' : number_format($h->agentsTotal) }}
                    @if($h->agentsUnresponsive !== null)
                        · {{ number_format($h->agentsUnresponsive) }} unresponsive
                    @endif
                    @if($isolated !== null)
                        · {{ number_format($isolated) }} isolated
                    @endif
                </p>
            @else
                <p class="text-sm portal-body-muted mt-2">
                    @if($viewerIsTechnician)
                        {{ $h->unavailableReason }}
                    @elseif(str_contains((string) $h->unavailableReason, 'API is not configured'))
                        Security monitoring is not enabled for this organisation yet.
                    @elseif(str_contains((string) $h->unavailableReason, 'not connected'))
                        Security monitoring is not linked for this organisation yet.
                    @else
                        Security figures are not available yet.
                    @endif
                </p>
            @endif
        </div>
    </div>
</x-card>
