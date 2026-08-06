    @php
        $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
        $lastUk = $summary->lastRefreshedAt
            ? $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i').' UK'
            : null;
        $ageMinutes = $summary->lastRefreshedAt
            ? (int) round($summary->lastRefreshedAt->diffInMinutes(now()))
            : null;
    @endphp
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        @if($lastUk)
            <p class="portal-body-muted text-xs">
                @if($viewerIsTechnician)
                    SuperOps last success {{ $lastUk }}
                    @if($ageMinutes !== null)
                        ({{ $ageMinutes }}m ago · requeue ≥{{ config('services.superops.dashboard_refresh_after_minutes', 2.5) }}m · client note ≥{{ config('services.superops.dashboard_cache_minutes', 5) }}m)
                    @endif
                @else
                    Overview as of {{ $lastUk }}
                @endif
            </p>
        @else
            <span></span>
        @endif
        @if($organisationWide ?? true)
            <form method="POST" action="{{ route('client-admin.refresh') }}">
                @csrf
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto">Refresh now</button>
            </form>
        @endif
    </div>

    {{-- System health — modular tiles from DashboardFeedRegistry --}}
    <section class="mb-8">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h2 class="portal-label">System health</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach(($dashboardFeeds ?? app(\App\Services\Portal\DashboardFeedRegistry::class))->overviewTiles() as $feed)
                @php
                    $orgWide = $organisationWide ?? true;
                    // Licence fleet is Client Admin only; Dropsuite shows personal last-run for requesters.
                    $skip = ! $orgWide && $feed->key() === 'm365_insights';
                @endphp
                @continue($skip)
                @include($feed->overviewPartial(), [
                    'viewerIsTechnician' => $viewerIsTechnician,
                    'organisationWide' => $orgWide,
                ])
            @endforeach
        </div>
    </section>

    {{-- Support & SLA --}}
    <section class="mb-8">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h2 class="portal-label">Support &amp; SLA</h2>
            @if($summary->hasData())
                <a href="{{ route('integrations.superops.launch') }}" class="text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">Open SuperOps &rarr;</a>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <x-card>
                <p class="portal-label mb-2">Open tickets</p>
                <p class="text-4xl font-condensed font-bold text-onit">
                    {{ $summary->openTicketsTotal === null ? '-' : number_format($summary->openTicketsTotal) }}
                </p>
                @if($summary->openTicketsByPriority !== [])
                    <div class="flex flex-wrap gap-2 mt-4">
                        @foreach($summary->openTicketsByPriority as $priority => $count)
                            <span class="text-xs border border-white/15 px-2 py-1 text-white/80">
                                {{ $priority }}: {{ $count }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card>
                <p class="portal-label mb-2">SLA performance</p>
                <p class="text-4xl font-condensed font-bold text-white">
                    {{ $summary->slaMetPercent === null ? '-' : $summary->slaMetPercent.'%' }}
                </p>
                <p class="portal-body-muted text-sm mt-2">
                    Resolution SLA met (30 days)
                    @if($summary->slaSampleSize)
                        / {{ number_format($summary->slaSampleSize) }} tickets
                    @endif
                </p>
            </x-card>

            <x-card x-data="{ range: '7' }">
                <p class="portal-label mb-2">Ticket activity</p>
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach(['7' => '7d', '14' => '14d', '30' => '30d', 'all' => 'All'] as $key => $label)
                        <button type="button"
                                @click="range = '{{ $key }}'"
                                :class="range === '{{ $key }}' ? 'border-onit text-onit' : 'border-white/15 text-white/60'"
                                class="px-2 py-1 text-xs border font-condensed uppercase tracking-wide">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @foreach(['7', '14', '30', 'all'] as $key)
                    <div x-show="range === '{{ $key }}'" class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs portal-body-muted mb-1">Logged</p>
                            <p class="text-2xl font-condensed font-bold text-white">
                                @php $logged = $summary->ticketsCreated[$key] ?? null; @endphp
                                {{ $logged === null ? '-' : number_format($logged) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs portal-body-muted mb-1">Closed</p>
                            <p class="text-2xl font-condensed font-bold text-white">
                                @php $closed = $summary->ticketsClosed[$key] ?? null; @endphp
                                {{ $closed === null ? '-' : number_format($closed) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </x-card>
        </div>

        @if($summary->openTicketsTable !== [])
            <x-card>
                <p class="portal-label mb-4">Open tickets</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-white/10 text-white/60">
                                <th class="py-2 pr-4 font-normal">ID</th>
                                <th class="py-2 pr-4 font-normal">Subject</th>
                                <th class="py-2 pr-4 font-normal">Priority</th>
                                <th class="py-2 pr-4 font-normal">Status</th>
                                <th class="py-2 font-normal">Opened</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summary->openTicketsTable as $ticket)
                                <tr class="border-b border-white/5">
                                    <td class="py-3 pr-4 text-onit font-mono text-xs">{{ $ticket['displayId'] }}</td>
                                    <td class="py-3 pr-4 text-white/90">{{ $ticket['subject'] }}</td>
                                    <td class="py-3 pr-4 text-white/70">{{ $ticket['priority'] ?: '-' }}</td>
                                    <td class="py-3 pr-4 text-white/70">{{ $ticket['status'] }}</td>
                                    <td class="py-3 text-white/60 text-xs">
                                        @if(filled($ticket['createdTime']))
                                            {{ \Carbon\Carbon::parse($ticket['createdTime'])->timezone('Europe/London')->format('d M Y') }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif
    </section>

    {{-- M365 detail --}}
    @if($m365Insights->hasData() && $m365Insights->topSkus !== [])
        <section>
            <div class="flex items-center justify-between gap-4 mb-6">
                <h2 class="portal-label">Microsoft 365 licence insight</h2>
                @can('view-m365-directory')
                    <a href="{{ route('microsoft-365.directory') }}" class="text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">Full directory &rarr;</a>
                @endcan
            </div>

            <x-card>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Licensed users</p>
                        <p class="text-2xl font-condensed font-bold text-white">
                            {{ $m365Insights->licensedUserCount === null ? '-' : number_format($m365Insights->licensedUserCount) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Seats assigned</p>
                        <p class="text-2xl font-condensed font-bold text-white">
                            {{ $m365Insights->totalSeatsAssigned === null ? '-' : number_format($m365Insights->totalSeatsAssigned) }}
                            @if($m365Insights->totalSeatsPurchased !== null)
                                <span class="text-base text-white/50">/ {{ number_format($m365Insights->totalSeatsPurchased) }}</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Overall utilisation</p>
                        <p class="text-2xl font-condensed font-bold text-onit">
                            {{ $m365Insights->overallUtilizationPct === null ? '-' : number_format($m365Insights->overallUtilizationPct, 0).'%' }}
                        </p>
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach($m365Insights->topSkus as $sku)
                        <div>
                            <div class="flex justify-between text-xs mb-1 gap-3">
                                <span class="text-white/80">{{ $sku['displayName'] ?? $sku['skuPartNumber'] }}</span>
                                <span class="text-white/60 shrink-0">
                                    {{ $sku['assigned'] }} / {{ $sku['purchased'] }}
                                    @if(($sku['countsTowardUtilisation'] ?? true) === false)
                                        - Free / preview
                                    @else
                                        ({{ number_format($sku['utilizationPct'], 0) }}%)
                                    @endif
                                </span>
                            </div>
                            <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                <div class="h-full bg-onit rounded-full" style="width: {{ min(100, $sku['utilizationPct']) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </section>
    @endif
