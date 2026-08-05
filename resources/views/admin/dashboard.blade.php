<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Admin Dashboard'])

    <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <p class="admin-stat-label">Clients</p>
            <p class="admin-stat-value">{{ $stats['clients'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Users</p>
            <p class="admin-stat-value">{{ $stats['users'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Active Notices</p>
            <p class="admin-stat-value">{{ $stats['notices'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Queue pending</p>
            <p class="admin-stat-value">{{ $integrationHealth['queue']['pending'] }}</p>
            <p class="mt-2 text-xs text-white/50">
                high {{ $integrationHealth['queue']['high'] }}
                · default {{ $integrationHealth['queue']['default'] }}
                · failed {{ $integrationHealth['queue']['failed'] }}
                @if($integrationHealth['queue']['oldest_pending_seconds'] !== null)
                    · oldest {{ number_format($integrationHealth['queue']['oldest_pending_seconds'] / 60, 1) }}m
                @endif
            </p>
        </x-card>
    </div>

    {{-- Technician-only: per-client refresh health (not shown to client portal users) --}}
    <div class="admin-table-wrap mb-8">
        <div class="border-b border-white/10 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="admin-section-title mb-0">Integration refresh health</h2>
                <p class="text-xs text-white/50 mt-1">
                    On IT technicians only — last successful refresh per client and what is queued / stuck.
                    @if($integrationHealth['stuck_count'] > 0)
                        <span class="text-amber-300">{{ $integrationHealth['stuck_count'] }} stuck</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="text-xs text-onit hover:text-white uppercase tracking-wide">Reload</a>
        </div>

        @if(count($integrationHealth['clients']) === 0)
            <div class="px-6 py-12"><x-empty-state title="No active clients" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">Client</th>
                            <th class="text-left">SuperOps</th>
                            <th class="text-left">M365 directory</th>
                            <th class="text-left">M365 licences</th>
                            <th class="text-left">Entra sync</th>
                            <th class="text-left">Active / stuck</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($integrationHealth['clients'] as $row)
                            @php
                                $byKey = collect($row['integrations'])->keyBy('key');
                                $statusClass = [
                                    'ok' => 'text-emerald-400',
                                    'cold' => 'text-white/40',
                                    'queued' => 'text-sky-300',
                                    'running' => 'text-onit',
                                    'stuck' => 'text-amber-300',
                                    'failed' => 'text-rose-400',
                                    'disabled' => 'text-white/30',
                                ];
                            @endphp
                            <tr class="{{ $row['is_stuck'] ? 'bg-amber-500/5' : '' }}">
                                <td class="align-top">
                                    <a href="{{ route('admin.clients.edit', $row['client_id']) }}" class="text-white hover:text-onit font-medium">
                                        {{ $row['client_name'] }}
                                    </a>
                                </td>
                                @foreach(['superops', 'm365_directory', 'm365_insights', 'entra_sync'] as $key)
                                    @php $cell = $byKey[$key]; @endphp
                                    <td class="align-top {{ $statusClass[$cell['status']] ?? 'text-white/60' }}">
                                        <div class="font-medium uppercase text-[10px] tracking-wide">{{ $cell['status'] }}</div>
                                        @if($cell['last_success_at'])
                                            <div class="text-white/70">
                                                {{ $cell['last_success_at']->timezone('Europe/London')->format('d M H:i') }} UK
                                            </div>
                                            <div class="text-white/40 text-xs">
                                                {{ $cell['age_minutes'] }}m ago
                                                @if($cell['duration_ms'] !== null)
                                                    · last ran {{ number_format($cell['duration_ms'] / 1000, 1) }}s
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-white/40 text-xs">{{ $cell['detail'] }}</div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="align-top text-xs">
                                    @if($row['active_process'])
                                        <span class="{{ $row['is_stuck'] ? 'text-amber-300' : 'text-sky-300' }}">
                                            {{ $row['active_process'] }}
                                        </span>
                                        <div class="text-white/50 mt-1 max-w-xs">{{ $row['active_detail'] }}</div>
                                    @else
                                        <span class="text-white/30">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

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
