{{-- Huntress security — DashboardFeed key: huntress --}}
@php
    $h = $huntressSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $canOpenCases = auth()->user()?->can('view-huntress-security') ?? false;
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="portal-label mb-2">Security (Huntress)</p>
            @if($h->hasData())
                @php
                    $openIncidents = $h->openIncidents;
                    $resolved = $h->resolvedIncidents;
                    $isolated = $h->edrIsolatedAgents;
                    $attention = ($openIncidents ?? 0) > 0 || ($isolated ?? 0) > 0;
                @endphp
                <p class="text-4xl font-condensed font-bold {{ $attention ? 'text-amber-300' : 'text-white' }}">
                    {{ $openIncidents === null ? '-' : number_format($openIncidents) }}
                </p>
                <p class="portal-body-muted text-sm mt-2 leading-relaxed">Active cases</p>
                <p class="portal-body-muted text-xs mt-3 leading-relaxed">
                    Resolved {{ $resolved === null ? '—' : number_format($resolved) }}
                    @if($h->agentsTotal !== null)
                        · Agents {{ number_format($h->agentsTotal) }}
                    @endif
                    @if($h->agentsUnresponsive !== null)
                        · {{ number_format($h->agentsUnresponsive) }} unresponsive
                    @endif
                    @if($isolated !== null)
                        · {{ number_format($isolated) }} isolated
                    @endif
                </p>
                @if($canOpenCases)
                    <a href="{{ route('security.huntress.index') }}" class="inline-block mt-4 text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">
                        Security dashboard &rarr;
                    </a>
                @endif
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
