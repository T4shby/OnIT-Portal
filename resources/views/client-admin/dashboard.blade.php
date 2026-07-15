<x-app-layout title="Client Admin" content-class="max-w-[96rem]">

    @php
        $refreshing = $summary->refreshInProgress
            || $m365Insights->refreshInProgress
            || $huntressSummary->refreshInProgress
            || $dropsuiteSummary->refreshInProgress;
    @endphp

    <section class="mb-6">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Organisation</h1>
            <h1 class="section-heading-orange">Overview</h1>
        </div>
        <p class="portal-body-muted max-w-3xl">
            Are your systems healthy? Are issues being dealt with? What value are you getting from On IT?
            Summary for <strong class="text-white/80">{{ $client->name }}</strong>.
        </p>
    </section>

    @if($summary->unavailableReason && ! $summary->hasData())
        <x-alert type="warning" class="mb-6">{{ $summary->unavailableReason }}</x-alert>
    @elseif($summary->isStale && $summary->lastRefreshedAt)
        <x-alert type="warning" class="mb-6">
            Some data is stale. Last SuperOps refresh
            {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
        </x-alert>
    @endif

    @if($refreshing)
        <x-alert type="info" class="mb-6">Refresh in progress. Counts will update shortly.</x-alert>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        @if($summary->lastRefreshedAt)
            <p class="portal-body-muted text-xs">
                SuperOps last refreshed {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK
            </p>
        @else
            <span></span>
        @endif
        <form method="POST" action="{{ route('client-admin.refresh') }}">
            @csrf
            <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto">Refresh now</button>
        </form>
    </div>

    {{-- System health --}}
    <section class="mb-8">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h2 class="portal-label">System health</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="portal-label mb-2">Managed devices</p>
                        <p class="text-4xl font-condensed font-bold text-onit">
                            {{ $summary->assetsTotal === null ? '—' : number_format($summary->assetsTotal) }}
                        </p>
                        <p class="portal-body-muted text-sm mt-2">
                            @if($summary->assetsOnline !== null && $summary->assetsOffline !== null)
                                {{ number_format($summary->assetsOnline) }} online · {{ number_format($summary->assetsOffline) }} offline
                            @else
                                Devices in SuperOps
                            @endif
                        </p>
                    </div>
                    @if($summary->hasData())
                        <a href="{{ route('integrations.superops.launch') }}" class="text-onit hover:text-white text-lg leading-none" title="Open SuperOps">→</a>
                    @endif
                </div>
            </x-card>

            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="portal-label mb-2">Security (Huntress)</p>
                        @if($huntressSummary->hasData())
                            <p class="text-4xl font-condensed font-bold text-white">
                                {{ $huntressSummary->openIncidents === null ? '—' : number_format($huntressSummary->openIncidents) }}
                            </p>
                            <p class="portal-body-muted text-sm mt-2">
                                Open incidents
                                @if($huntressSummary->edrIsolatedAgents !== null)
                                    · {{ number_format($huntressSummary->edrIsolatedAgents) }} isolated
                                @endif
                            </p>
                        @else
                            <p class="text-sm portal-body-muted mt-2">{{ $huntressSummary->unavailableReason }}</p>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="portal-label mb-2">Backups (Dropsuite)</p>
                        @if($dropsuiteSummary->hasData())
                            <p class="text-4xl font-condensed font-bold text-white">
                                {{ $dropsuiteSummary->protectedMailboxes === null ? '—' : number_format($dropsuiteSummary->protectedMailboxes) }}
                            </p>
                            <p class="portal-body-muted text-sm mt-2">
                                Protected mailboxes · {{ ucfirst($dropsuiteSummary->lastBackupStatus) }}
                            </p>
                        @else
                            <p class="text-sm portal-body-muted mt-2">{{ $dropsuiteSummary->unavailableReason }}</p>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="portal-label mb-2">Microsoft 365</p>
                        @if($m365Insights->hasData())
                            <p class="text-4xl font-condensed font-bold text-onit">
                                {{ $m365Insights->overallUtilizationPct === null ? '—' : number_format($m365Insights->overallUtilizationPct, 0).'%' }}
                            </p>
                            <p class="portal-body-muted text-sm mt-2">
                                Licence utilisation
                                @if($m365Insights->licensedUserCount !== null)
                                    · {{ number_format($m365Insights->licensedUserCount) }} users
                                @endif
                            </p>
                        @else
                            <p class="text-sm portal-body-muted mt-2">{{ $m365Insights->unavailableReason ?? 'Not available yet.' }}</p>
                        @endif
                    </div>
                    @can('view-m365-directory')
                        <a href="{{ route('microsoft-365.directory') }}" class="text-onit hover:text-white text-lg leading-none" title="Microsoft 365 directory">→</a>
                    @endcan
                </div>
            </x-card>
        </div>
    </section>

    {{-- Support & SLA --}}
    <section class="mb-8">
        <div class="flex items-center justify-between gap-4 mb-6">
            <h2 class="portal-label">Support &amp; SLA</h2>
            @if($summary->hasData())
                <a href="{{ route('integrations.superops.launch') }}" class="text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">Open SuperOps →</a>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <x-card>
                <p class="portal-label mb-2">Open tickets</p>
                <p class="text-4xl font-condensed font-bold text-onit">
                    {{ $summary->openTicketsTotal === null ? '—' : number_format($summary->openTicketsTotal) }}
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
                    {{ $summary->slaMetPercent === null ? '—' : $summary->slaMetPercent.'%' }}
                </p>
                <p class="portal-body-muted text-sm mt-2">
                    Resolution SLA met (30 days)
                    @if($summary->slaSampleSize)
                        · {{ number_format($summary->slaSampleSize) }} tickets
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
                                {{ $logged === null ? '—' : number_format($logged) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs portal-body-muted mb-1">Closed</p>
                            <p class="text-2xl font-condensed font-bold text-white">
                                @php $closed = $summary->ticketsClosed[$key] ?? null; @endphp
                                {{ $closed === null ? '—' : number_format($closed) }}
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
                                    <td class="py-3 pr-4 text-white/70">{{ $ticket['priority'] ?: '—' }}</td>
                                    <td class="py-3 pr-4 text-white/70">{{ $ticket['status'] }}</td>
                                    <td class="py-3 text-white/60 text-xs">
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
            </x-card>
        @endif
    </section>

    {{-- M365 detail --}}
    @if($m365Insights->hasData() && $m365Insights->topSkus !== [])
        <section>
            <div class="flex items-center justify-between gap-4 mb-6">
                <h2 class="portal-label">Microsoft 365 licence insight</h2>
                @can('view-m365-directory')
                    <a href="{{ route('microsoft-365.directory') }}" class="text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">Full directory →</a>
                @endcan
            </div>

            <x-card>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Licensed users</p>
                        <p class="text-2xl font-condensed font-bold text-white">
                            {{ $m365Insights->licensedUserCount === null ? '—' : number_format($m365Insights->licensedUserCount) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Seats assigned</p>
                        <p class="text-2xl font-condensed font-bold text-white">
                            {{ $m365Insights->totalSeatsAssigned === null ? '—' : number_format($m365Insights->totalSeatsAssigned) }}
                            @if($m365Insights->totalSeatsPurchased !== null)
                                <span class="text-base text-white/50">/ {{ number_format($m365Insights->totalSeatsPurchased) }}</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs portal-body-muted mb-1">Overall utilisation</p>
                        <p class="text-2xl font-condensed font-bold text-onit">
                            {{ $m365Insights->overallUtilizationPct === null ? '—' : number_format($m365Insights->overallUtilizationPct, 0).'%' }}
                        </p>
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach($m365Insights->topSkus as $sku)
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-white/80">{{ $sku['skuPartNumber'] }}</span>
                                <span class="text-white/60">{{ $sku['assigned'] }} / {{ $sku['purchased'] }} ({{ number_format($sku['utilizationPct'], 0) }}%)</span>
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

</x-app-layout>
