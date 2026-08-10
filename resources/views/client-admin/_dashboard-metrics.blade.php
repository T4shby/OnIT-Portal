@php
    $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
    $lastUk = $summary->lastRefreshedAt
        ? $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i').' UK'
        : null;
    $ageMinutes = $summary->lastRefreshedAt
        ? (int) round($summary->lastRefreshedAt->diffInMinutes(now()))
        : null;
    $orgWide = $organisationWide ?? true;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $viewer = auth()->user();
    $systemHealthTiles = [];
    foreach (($dashboardFeeds ?? app(\App\Services\Portal\DashboardFeedRegistry::class))->overviewTiles() as $feed) {
        $feedKey = $feed->key();
        if (! $orgWide && $feedKey === 'm365_insights') {
            continue;
        }
        if (! $products->shouldShowOverviewTile($client, $feedKey, $viewer)) {
            continue;
        }
        $tileLabel = match ($feedKey) {
            'superops' => $orgWide ? 'Managed devices' : 'Your tickets',
            'huntress' => 'Security',
            'dropsuite' => $orgWide ? 'Backups' : 'My backup',
            'm365_insights' => 'Microsoft 365',
            default => $feed->label(),
        };
        $systemHealthTiles[] = [
            'feed' => $feed,
            'key' => $feedKey,
            'label' => $tileLabel,
            'not_sold' => $viewer
                && $viewer->isClientAdmin()
                && ! $viewer->isTeamMember()
                && ! $products->isEntitled($client, $feedKey),
        ];
    }
@endphp

{{-- Toolbar --}}
<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 16px;margin-bottom:1.25rem">
    @if($lastUk)
        <p class="org-muted" style="margin:0;font-size:12px">
            @if($viewerIsTechnician)
                SuperOps last success {{ $lastUk }}
                @if($ageMinutes !== null)
                    ({{ $ageMinutes }}m ago)
                @endif
            @else
                Updated {{ $lastUk }}
            @endif
        </p>
    @else
        <span></span>
    @endif
    @if($organisationWide ?? true)
        <form method="POST" action="{{ route('client-admin.refresh') }}" style="margin:0">
            @csrf
            <button type="submit" class="org-cta">Refresh now</button>
        </form>
    @endif
</div>

{{-- System health — dense equal grid (no full-width empty cards) --}}
<section style="margin-bottom:1.75rem">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:12px">
        <h2 class="org-label" style="margin:0">
            {{ $orgWide ? 'System health' : 'Your services' }}
        </h2>
    </div>

    <div class="org-grid">
        @foreach($systemHealthTiles as $tile)
            <div class="min-w-0">
                @if($tile['not_sold'])
                    @include('client-admin.feeds._not-sold', ['label' => $tile['label']])
                @else
                    @include($tile['feed']->overviewPartial(), [
                        'viewerIsTechnician' => $viewerIsTechnician,
                        'organisationWide' => $orgWide,
                        'client' => $client,
                        'tileLabel' => $tile['label'],
                    ])
                @endif
            </div>
        @endforeach
    </div>
</section>

{{-- Support & SLA --}}
@php
    $showSupport = $products->shouldShowForViewer($client, 'superops', auth()->user());
    $superOpsNeedsAm = $products->needsAccountManagerHelp($client, 'superops');
@endphp
@if($showSupport)
<section style="margin-bottom:1.75rem">
    <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px">
        <h2 class="org-label" style="margin:0">Support &amp; response times</h2>
        @if($summary->hasData())
            <a href="{{ route('integrations.superops.launch') }}" class="org-link">Open SuperOps →</a>
        @endif
    </div>

    @if(! $summary->hasData() && $superOpsNeedsAm && ! $viewerIsTechnician)
        <div class="org-card org-card-pad">
            <p class="org-muted" style="margin:0;font-size:13px;line-height:1.5">
                Please contact your account manager to get this sorted.
            </p>
        </div>
    @else
        <div class="org-support-grid" style="margin-bottom:1rem">
            <div class="org-card org-card-pad">
                <p class="org-label" style="margin:0 0 8px">Open tickets</p>
                <p class="org-hero-num org-accent" style="margin:0">
                    {{ $summary->openTicketsTotal === null ? '—' : number_format($summary->openTicketsTotal) }}
                </p>
                @if($summary->openTicketsByPriority !== [])
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px">
                        @foreach($summary->openTicketsByPriority as $priority => $count)
                            <span class="org-chip">{{ $priority }}: {{ $count }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="org-card org-card-pad">
                <p class="org-label" style="margin:0 0 8px">SLA performance</p>
                <p class="org-hero-num" style="margin:0">
                    {{ $summary->slaMetPercent === null ? '—' : $summary->slaMetPercent.'%' }}
                </p>
                <p class="org-muted" style="margin:8px 0 0;font-size:12px;line-height:1.4">
                    Tickets answered on time (last 30 days)
                    @if($summary->slaSampleSize)
                        · {{ number_format($summary->slaSampleSize) }} tickets
                    @endif
                </p>
            </div>

            <div class="org-card org-card-pad" x-data="{ range: '7' }">
                <p class="org-label" style="margin:0 0 8px">Ticket activity</p>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
                    @foreach(['7' => '7d', '14' => '14d', '30' => '30d', 'all' => 'All'] as $key => $label)
                        <button type="button"
                                @click="range = '{{ $key }}'"
                                :class="range === '{{ $key }}' ? 'org-range-btn is-on' : 'org-range-btn'"
                                class="org-range-btn">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @foreach(['7', '14', '30', 'all'] as $key)
                    <div x-show="range === '{{ $key }}'" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div>
                            <p class="org-muted" style="margin:0 0 4px;font-size:11px">Logged</p>
                            <p style="margin:0;font-size:1.35rem;font-weight:700">
                                @php $logged = $summary->ticketsCreated[$key] ?? null; @endphp
                                {{ $logged === null ? '—' : number_format($logged) }}
                            </p>
                        </div>
                        <div>
                            <p class="org-muted" style="margin:0 0 4px;font-size:11px">Closed</p>
                            <p style="margin:0;font-size:1.35rem;font-weight:700">
                                @php $closed = $summary->ticketsClosed[$key] ?? null; @endphp
                                {{ $closed === null ? '—' : number_format($closed) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @if($summary->openTicketsTable !== [])
            <div class="org-card org-card-pad" style="padding-top:1rem;padding-bottom:.5rem">
                <p class="org-label" style="margin:0 0 10px">Open tickets</p>
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
                    <table class="org-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Opened</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summary->openTicketsTable as $ticket)
                                <tr>
                                    <td style="color:#FF7000;font-family:ui-monospace,monospace;font-size:12px;white-space:nowrap">{{ $ticket['displayId'] }}</td>
                                    <td style="color:rgba(255,255,255,.9);min-width:10rem">{{ $ticket['subject'] }}</td>
                                    <td class="org-muted" style="white-space:nowrap">{{ $ticket['priority'] ?: '—' }}</td>
                                    <td class="org-muted" style="white-space:nowrap">{{ $ticket['status'] }}</td>
                                    <td class="org-muted" style="font-size:12px;white-space:nowrap">
                                        @if(filled($ticket['createdTime']))
                                            {{ \Carbon\Carbon::parse($ticket['createdTime'])->timezone('Europe/London')->format('d M Y') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</section>
@endif

{{-- M365 licence breakdown --}}
@if($m365Insights->hasData() && $m365Insights->topSkus !== [])
    <section>
        <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px">
            <h2 class="org-label" style="margin:0">Microsoft 365 licences</h2>
            @can('view-m365-directory')
                <a href="{{ route('microsoft-365.directory') }}" class="org-link">Full directory →</a>
            @endcan
        </div>

        <div class="org-card org-card-pad">
            <div class="org-support-grid" style="margin-bottom:1.25rem">
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Licensed users</p>
                    <p style="margin:0;font-size:1.35rem;font-weight:700">
                        {{ $m365Insights->licensedUserCount === null ? '—' : number_format($m365Insights->licensedUserCount) }}
                    </p>
                    <p class="org-muted" style="margin:4px 0 0;font-size:11px">User mailboxes only</p>
                </div>
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Paid seats assigned</p>
                    <p style="margin:0;font-size:1.35rem;font-weight:700">
                        {{ $m365Insights->totalSeatsAssigned === null ? '—' : number_format($m365Insights->totalSeatsAssigned) }}
                        @if($m365Insights->totalSeatsPurchased !== null)
                            <span class="org-muted" style="font-size:1rem;font-weight:500">/ {{ number_format($m365Insights->totalSeatsPurchased) }}</span>
                        @endif
                    </p>
                </div>
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Paid utilisation</p>
                    <p class="org-hero-num org-accent" style="margin:0;font-size:1.35rem">
                        {{ $m365Insights->overallUtilizationPct === null ? '—' : number_format($m365Insights->overallUtilizationPct, 0).'%' }}
                    </p>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:12px">
                @foreach($m365Insights->topSkus as $sku)
                    <div>
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;margin-bottom:4px">
                            <span style="color:rgba(255,255,255,.85)">{{ $sku['displayName'] ?? $sku['skuPartNumber'] }}</span>
                            <span class="org-muted" style="flex:none">
                                {{ $sku['assigned'] }} / {{ $sku['purchased'] }}
                                @if(($sku['countsTowardUtilisation'] ?? true) === false)
                                    · Free / trial
                                @else
                                    ({{ number_format($sku['utilizationPct'], 0) }}%)
                                @endif
                            </span>
                        </div>
                        <div style="height:6px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden">
                            <div style="height:100%;width:{{ min(100, $sku['utilizationPct']) }}%;background:#FF7000;border-radius:999px"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
