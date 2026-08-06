{{-- Huntress security — DashboardFeed key: huntress --}}
@php
    $h = $huntressSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $canOpenCases = auth()->user()?->can('view-huntress-security') ?? false;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'huntress');
    $mapped = $products->isMapped($client, 'huntress');
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
            @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
                <p class="text-sm portal-body-muted mt-2 leading-relaxed">Security figures are not available yet.</p>
            @else
                @include('client-admin.feeds._unavailable', [
                    'viewerIsTechnician' => $viewerIsTechnician,
                    'unavailableReason' => $h->unavailableReason,
                    'pendingLabel' => 'Security figures',
                    'needsAccountManager' => $needsAm || ! $mapped,
                ])
            @endif
        </div>
    </div>
</x-card>
