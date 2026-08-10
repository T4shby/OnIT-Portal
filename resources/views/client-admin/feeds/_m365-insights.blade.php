{{-- M365 licences tile — DashboardFeed key: m365_insights --}}
@php
    $m = $m365Insights;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'm365');
    $mapped = $products->isMapped($client, 'm365');
    $title = $tileLabel ?? 'Microsoft 365';
@endphp
<div class="org-card org-card-pad">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
        <p class="org-label" style="margin:0">{{ $title }}</p>
        @can('view-m365-directory')
            <a href="{{ route('microsoft-365.directory') }}" class="org-link" title="Directory">→</a>
        @endcan
    </div>

    @if($m->hasData())
        <p class="org-hero-num org-accent" style="margin:8px 0 0">
            {{ $m->overallUtilizationPct === null ? '—' : number_format($m->overallUtilizationPct, 0).'%' }}
        </p>
        <p class="org-muted" style="margin:4px 0 0;font-size:12px">paid licence utilisation</p>
        <div class="org-stack">
            <div class="org-metric">
                <span class="org-metric-l">Licensed users</span>
                <span class="org-metric-v">{{ $m->licensedUserCount === null ? '—' : number_format($m->licensedUserCount) }}</span>
            </div>
            <div class="org-metric">
                <span class="org-metric-l">Seats assigned</span>
                <span class="org-metric-v">
                    {{ $m->totalSeatsAssigned === null ? '—' : number_format($m->totalSeatsAssigned) }}
                    @if($m->totalSeatsPurchased !== null)
                        <span style="font-weight:500;color:rgba(255,255,255,.45)">/ {{ number_format($m->totalSeatsPurchased) }}</span>
                    @endif
                </span>
            </div>
        </div>
        <p class="org-muted" style="margin:12px 0 0;font-size:11px">User mailboxes only</p>
        @can('view-m365-directory')
            <a href="{{ route('microsoft-365.directory') }}" class="org-link" style="margin-top:14px">View directory →</a>
        @endcan
    @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
        <p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">Licence figures are not available yet.</p>
    @else
        @include('client-admin.feeds._unavailable', [
            'viewerIsTechnician' => $viewerIsTechnician,
            'unavailableReason' => $m->unavailableReason ?? 'Microsoft 365 is not connected for this organisation.',
            'pendingLabel' => 'Licence figures',
            'needsAccountManager' => $needsAm || ! $mapped,
        ])
    @endif
</div>
