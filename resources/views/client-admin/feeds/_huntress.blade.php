{{-- Huntress security — DashboardFeed key: huntress --}}
@php
    $h = $huntressSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $canOpenCases = auth()->user()?->can('view-huntress-security') ?? false;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'huntress');
    $mapped = $products->isMapped($client, 'huntress');
    $title = $tileLabel ?? 'Security';
@endphp
<div class="org-card org-card-pad">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
        <p class="org-label" style="margin:0">{{ $title }}</p>
        @if($h->hasData() && $canOpenCases)
            <a href="{{ route('security.huntress.index') }}" class="org-link" title="Security cases">→</a>
        @endif
    </div>

    @if($h->hasData())
        @php
            $openIncidents = $h->openIncidents;
            $resolved = $h->resolvedIncidents;
            $isolated = $h->edrIsolatedAgents;
            $attention = ($openIncidents ?? 0) > 0 || ($isolated ?? 0) > 0;
        @endphp
        <p class="org-hero-num {{ $attention ? 'org-warn' : '' }}" style="margin:8px 0 0">
            {{ $openIncidents === null ? '—' : number_format($openIncidents) }}
        </p>
        <p class="org-muted" style="margin:4px 0 0;font-size:12px">open security cases</p>
        <div class="org-stack">
            <div class="org-metric">
                <span class="org-metric-l">Resolved</span>
                <span class="org-metric-v">{{ $resolved === null ? '—' : number_format($resolved) }}</span>
            </div>
            @if($h->agentsTotal !== null)
                <div class="org-metric">
                    <span class="org-metric-l">Devices protected</span>
                    <span class="org-metric-v">{{ number_format($h->agentsTotal) }}</span>
                </div>
            @endif
            @if($h->agentsUnresponsive !== null)
                <div class="org-metric">
                    <span class="org-metric-l">Not reporting</span>
                    <span class="org-metric-v">{{ number_format($h->agentsUnresponsive) }}</span>
                </div>
            @endif
            @if($isolated !== null)
                <div class="org-metric">
                    <span class="org-metric-l">Isolated</span>
                    <span class="org-metric-v">{{ number_format($isolated) }}</span>
                </div>
            @endif
        </div>
        @if($canOpenCases)
            <a href="{{ route('security.huntress.index') }}" class="org-link" style="margin-top:14px">View cases →</a>
        @endif
    @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
        <p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">Security figures are not available yet.</p>
    @else
        @include('client-admin.feeds._unavailable', [
            'viewerIsTechnician' => $viewerIsTechnician,
            'unavailableReason' => $h->unavailableReason,
            'pendingLabel' => 'Security figures',
            'needsAccountManager' => $needsAm || ! $mapped,
        ])
    @endif
</div>
