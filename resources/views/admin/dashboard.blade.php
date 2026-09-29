<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Admin Dashboard'])

    <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <x-card>
            <p class="admin-stat-label">Clients</p>
            <p class="admin-stat-value">{{ $stats['clients'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Users</p>
            <p class="admin-stat-value">{{ $stats['users'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Refresh pipeline</p>
            <p class="admin-stat-value">
                @if(($healthSummary['stuck'] ?? 0) > 0)
                    <span class="text-rose-400">{{ $healthSummary['stuck'] }} stuck</span>
                @elseif(($healthSummary['cold'] ?? 0) > 0)
                    <span class="text-amber-300">{{ $healthSummary['cold'] }} never loaded</span>
                @elseif(($healthSummary['due'] ?? 0) > 0)
                    <span class="text-sky-300">{{ $healthSummary['due'] }} due</span>
                @elseif(($healthSummary['pending'] ?? 0) > 0)
                    <span class="text-onit">{{ $healthSummary['pending'] }} queued</span>
                @else
                    OK
                @endif
            </p>
            <p class="mt-2 text-xs text-white/50">
                pending {{ $healthSummary['pending'] ?? 0 }}
                · aging {{ $healthSummary['aging'] ?? 0 }}
                · cold {{ $healthSummary['cold'] ?? 0 }}
            </p>
            <a href="{{ route('admin.integration-health.index') }}" class="mt-3 inline-block text-xs text-onit hover:text-white uppercase tracking-wide">
                Open Integration Health →
            </a>
        </x-card>
    </div>

    @php $coverage = $productCoverage ?? null; @endphp
    @if(is_array($coverage))
        <div class="mb-8">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="admin-section-title mb-1">Sold product coverage</h2>
                    <p class="text-xs text-white/50 max-w-2xl">
                        KPI: sold service feeds should be <strong class="text-white/70">live</strong> (snapshot present).
                        Target <strong class="text-white/70">0 never loaded (cold)</strong>. Setup = sold but map/platform pending.
                        Clients without Huntress still get a full support-led home - missing MDR is not “unprotected.”
                        See composition on <a href="{{ route('admin.clients.index') }}" class="text-onit hover:text-white">each client’s Edit</a> page.
                    </p>
                </div>
                <a href="{{ route('admin.integration-health.index') }}" class="text-xs text-onit hover:text-white uppercase tracking-wide">Detail →</a>
            </div>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <x-card>
                    <p class="admin-stat-label">Live %</p>
                    <p class="admin-stat-value text-onit">
                        {{ $coverage['live_pct'] === null ? '-' : number_format($coverage['live_pct'], 0).'%' }}
                    </p>
                </x-card>
                <x-card>
                    <p class="admin-stat-label">Sold feeds</p>
                    <p class="admin-stat-value">{{ number_format($coverage['sold_feed_cells'] ?? 0) }}</p>
                </x-card>
                <x-card>
                    <p class="admin-stat-label">Live path</p>
                    <p class="admin-stat-value">{{ number_format($coverage['live_feed_cells'] ?? 0) }}</p>
                </x-card>
                <x-card>
                    <p class="admin-stat-label">Setup needed</p>
                    <p class="admin-stat-value">{{ number_format($coverage['setup_feed_cells'] ?? 0) }}</p>
                </x-card>
                <x-card>
                    <p class="admin-stat-label">Never loaded</p>
                    <p class="admin-stat-value {{ ($coverage['cold_feed_cells'] ?? 0) > 0 ? 'text-amber-300' : '' }}">
                        {{ number_format($coverage['cold_feed_cells'] ?? 0) }}
                    </p>
                </x-card>
            </div>
            @if(($coverage['rows'] ?? []) !== [])
                <div class="admin-table-wrap">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Sold</th>
                                <th>Live</th>
                                <th>Setup</th>
                                <th>Cold</th>
                                <th>Failed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($coverage['rows'], 0, 15) as $row)
                                <tr>
                                    <td>
                                        @if(! empty($row['client_id']))
                                            <a href="{{ route('admin.clients.edit', $row['client_id']) }}#client-home-composition" class="text-onit hover:text-white">
                                                {{ $row['client_name'] }}
                                            </a>
                                        @else
                                            {{ $row['client_name'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['sold'] }}</td>
                                    <td>{{ $row['live'] }}</td>
                                    <td>{{ $row['setup'] }}</td>
                                    <td class="{{ ($row['cold'] ?? 0) > 0 ? 'text-amber-300' : '' }}">{{ $row['cold'] }}</td>
                                    <td class="{{ ($row['failed'] ?? 0) > 0 ? 'text-rose-400' : '' }}">{{ $row['failed'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if(count($coverage['rows']) > 15)
                        <p class="px-6 py-3 text-xs text-white/45">Showing 15 of {{ count($coverage['rows']) }} clients with sold feeds.</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="admin-table-wrap">
        <div class="border-b border-white/10 px-6 py-4">
            <h2 class="admin-section-title mb-0">Recent Activity</h2>
        </div>
        @if($recentActivity->isNotEmpty())
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>User</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentActivity as $log)
                        <tr>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->user?->name ?? 'System' }}</td>
                            <td class="text-white/50">{{ $log->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="px-6 py-12"><x-empty-state title="No activity yet" /></div>
        @endif
    </div>
</x-admin-layout>
