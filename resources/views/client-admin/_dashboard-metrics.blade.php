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
    $showSupport = $products->shouldShowForViewer($client, 'superops', auth()->user());
    $superOpsNeedsAm = $products->needsAccountManagerHelp($client, 'superops');
    $insights = $summary->deviceInsights ?? [];
    $offline30 = $insights['offline_30d'] ?? ['count' => 0, 'names' => []];
    $restart = $insights['needs_restart'] ?? ['count' => 0, 'names' => []];
    $patch = $insights['patch'] ?? ['fully' => 0, 'not_fully' => 0, 'unknown' => 0];
    $edition = $insights['edition'] ?? ['home' => 0, 'pro' => 0, 'server' => 0, 'other' => 0];
    $age = $insights['age'] ?? ['under_3' => 0, '3_to_5' => 0, 'over_5' => 0, 'unknown' => 0];
@endphp

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
    @if($orgWide)
        <form method="POST" action="{{ route('client-admin.refresh') }}" style="margin:0">
            @csrf
            <button type="submit" class="org-cta">Refresh now</button>
        </form>
    @endif
</div>

@if($showSupport)
    @if(! $summary->hasData() && $superOpsNeedsAm && ! $viewerIsTechnician)
        <div class="org-card org-card-pad">
            <p class="org-muted" style="margin:0;font-size:13px;line-height:1.5">
                Please contact your account manager to get this sorted.
            </p>
        </div>
    @else
        <section style="margin-bottom:1.75rem">
            <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px">
                <h2 class="org-label" style="margin:0">Tickets</h2>
                @if($summary->hasData())
                    <a href="{{ route('integrations.superops.launch') }}" class="org-link">Open SuperOps →</a>
                @endif
            </div>

            <div class="org-support-grid" style="margin-bottom:1rem">
                <div class="org-card org-card-pad">
                    <p class="org-label" style="margin:0 0 8px">Open tickets</p>
                    <p class="org-hero-num org-accent" style="margin:0">
                        {{ $summary->openTicketsTotal === null ? '-' : number_format($summary->openTicketsTotal) }}
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
                        {{ $summary->slaMetPercent === null ? '-' : $summary->slaMetPercent.'%' }}
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
                                    {{ $logged === null ? '-' : number_format($logged) }}
                                </p>
                            </div>
                            <div>
                                <p class="org-muted" style="margin:0 0 4px;font-size:11px">Closed</p>
                                <p style="margin:0;font-size:1.35rem;font-weight:700">
                                    @php $closed = $summary->ticketsClosed[$key] ?? null; @endphp
                                    {{ $closed === null ? '-' : number_format($closed) }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if($summary->ticketsByCategory !== [])
                <div class="org-card org-card-pad" style="margin-bottom:1rem">
                    <p class="org-label" style="margin:0 0 10px">Ticket categories</p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        @foreach($summary->ticketsByCategory as $category => $count)
                            <span class="org-chip">{{ $category }}: {{ $count }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @include('client-admin._ticket-table', [
                'title' => 'Open tickets',
                'tickets' => $summary->openTicketsTable,
                'empty' => 'No open tickets in this snapshot.',
                'showResolved' => false,
            ])

            @if($orgWide)
                <div style="height:1rem"></div>
                @include('client-admin._ticket-table', [
                    'title' => 'Recently closed',
                    'tickets' => $summary->closedTicketsTable,
                    'empty' => 'No closed tickets in this snapshot.',
                    'showResolved' => true,
                ])
            @endif
        </section>

        @if($orgWide)
            <section>
                <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px">
                    <h2 class="org-label" style="margin:0">Devices</h2>
                    @if($summary->hasData())
                        <a href="{{ route('integrations.superops.launch') }}" class="org-link">Open SuperOps →</a>
                    @endif
                </div>

                <div class="org-grid" style="margin-bottom:1rem">
                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0">Managed devices</p>
                        <p class="org-hero-num org-accent" style="margin:8px 0 0">
                            {{ $summary->assetsTotal === null ? '-' : number_format($summary->assetsTotal) }}
                        </p>
                        <div class="org-stack">
                            <div class="org-metric">
                                <span class="org-metric-l">Checking in</span>
                                <span class="org-metric-v">{{ $summary->assetsOnline === null ? '-' : number_format($summary->assetsOnline) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Not checking in</span>
                                <span class="org-metric-v">{{ $summary->assetsOffline === null ? '-' : number_format($summary->assetsOffline) }}</span>
                            </div>
                        </div>
                        <p class="org-muted" style="margin:12px 0 0;font-size:11px;line-height:1.35">
                            Offline can be normal for field kit without internet.
                        </p>
                    </div>

                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0">Offline 30+ days</p>
                        <p class="org-hero-num org-warn" style="margin:8px 0 0">
                            {{ number_format((int) ($offline30['count'] ?? 0)) }}
                        </p>
                        <p class="org-muted" style="margin:8px 0 0;font-size:12px">Last check-in older than 30 days</p>
                        @include('client-admin._device-name-pager', ['names' => $offline30['names'] ?? []])
                    </div>

                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0">Need a restart</p>
                        <p class="org-hero-num" style="margin:8px 0 0">
                            {{ number_format((int) ($restart['count'] ?? 0)) }}
                        </p>
                        <p class="org-muted" style="margin:8px 0 0;font-size:12px">Uptime of 2 days or more</p>
                        @include('client-admin._device-name-pager', ['names' => $restart['names'] ?? []])
                    </div>

                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0">Patching</p>
                        <div class="org-stack">
                            <div class="org-metric">
                                <span class="org-metric-l">Fully patched</span>
                                <span class="org-metric-v">{{ number_format((int) ($patch['fully'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Need patches</span>
                                <span class="org-metric-v">{{ number_format((int) ($patch['not_fully'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Unknown</span>
                                <span class="org-metric-v">{{ number_format((int) ($patch['unknown'] ?? 0)) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="org-support-grid">
                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0 0 8px">Windows edition</p>
                        <div class="org-stack" style="margin-top:0">
                            <div class="org-metric">
                                <span class="org-metric-l">Home</span>
                                <span class="org-metric-v">{{ number_format((int) ($edition['home'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Pro</span>
                                <span class="org-metric-v">{{ number_format((int) ($edition['pro'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Server</span>
                                <span class="org-metric-v">{{ number_format((int) ($edition['server'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Other / Mac</span>
                                <span class="org-metric-v">{{ number_format((int) ($edition['other'] ?? 0)) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="org-card org-card-pad">
                        <p class="org-label" style="margin:0 0 8px">Device age</p>
                        <p class="org-muted" style="margin:0 0 10px;font-size:12px">From SuperOps purchase date where recorded</p>
                        <div class="org-stack" style="margin-top:0">
                            <div class="org-metric">
                                <span class="org-metric-l">Under 3 years</span>
                                <span class="org-metric-v">{{ number_format((int) ($age['under_3'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">3-5 years</span>
                                <span class="org-metric-v">{{ number_format((int) ($age['3_to_5'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Over 5 years</span>
                                <span class="org-metric-v">{{ number_format((int) ($age['over_5'] ?? 0)) }}</span>
                            </div>
                            <div class="org-metric">
                                <span class="org-metric-l">Date unknown</span>
                                <span class="org-metric-v">{{ number_format((int) ($age['unknown'] ?? 0)) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endif
