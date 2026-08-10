{{-- SuperOps managed devices — DashboardFeed key: superops --}}
@php
    $s = $summary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'superops');
    $mapped = $products->isMapped($client, 'superops');
    $title = $tileLabel ?? ($orgWide ? 'Managed devices' : 'Your tickets');
@endphp
<div class="org-card org-card-pad">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
        <p class="org-label" style="margin:0">{{ $title }}</p>
        @if($s->hasData())
            <a href="{{ route('integrations.superops.launch') }}" class="org-link" title="Open SuperOps">→</a>
        @endif
    </div>

    @if($s->hasData())
        @if($orgWide)
            <p class="org-hero-num org-accent" style="margin:8px 0 0">
                {{ $s->assetsTotal === null ? '—' : number_format($s->assetsTotal) }}
            </p>
            <p class="org-muted" style="margin:4px 0 0;font-size:12px">devices managed</p>
            <div class="org-stack">
                <div class="org-metric">
                    <span class="org-metric-l">Checking in</span>
                    <span class="org-metric-v">{{ $s->assetsOnline === null ? '—' : number_format($s->assetsOnline) }}</span>
                </div>
                <div class="org-metric">
                    <span class="org-metric-l">Not checking in</span>
                    <span class="org-metric-v" style="font-weight:500;color:rgba(255,255,255,.55)">{{ $s->assetsOffline === null ? '—' : number_format($s->assetsOffline) }}</span>
                </div>
            </div>
            <p class="org-muted" style="margin:12px 0 0;font-size:11px;line-height:1.35">
                Offline can be normal for field kit without internet.
            </p>
        @else
            <p class="org-hero-num org-accent" style="margin:8px 0 0">
                {{ $s->openTicketsTotal === null ? '—' : number_format($s->openTicketsTotal) }}
            </p>
            <p class="org-muted" style="margin:4px 0 0;font-size:12px">your open tickets</p>
        @endif
    @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
        <p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">
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
