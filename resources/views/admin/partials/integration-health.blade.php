@php
    $q = $integrationHealth['queue'];
@endphp
<div
    id="integration-health-live"
    data-queue-pending="{{ $q['pending'] }}"
    data-queue-high="{{ $q['high'] }}"
    data-queue-default="{{ $q['default'] }}"
    data-queue-failed="{{ $q['failed'] }}"
    data-queue-oldest="{{ $q['oldest_pending_seconds'] ?? '' }}"
    data-stuck-count="{{ $integrationHealth['stuck_count'] }}"
>
    <div class="admin-table-wrap mb-8">
        <div class="border-b border-white/10 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="admin-section-title mb-0">Integration refresh health</h2>
                <p class="text-xs text-white/50 mt-1">
                    On IT technicians only — ages, queues, stuck jobs.
                    SuperOps requeue ≥{{ (int) config('services.superops.dashboard_refresh_after_minutes', 10) }}m ·
                    client note ≥{{ (int) config('services.superops.dashboard_cache_minutes', 15) }}m ·
                    prewarm every 5m · workers drain <code class="text-white/40">high,default</code>.
                    <span class="text-emerald-400/80">Live · updates every 5s</span>
                    @if($integrationHealth['stuck_count'] > 0)
                        <span class="text-amber-300"> · {{ $integrationHealth['stuck_count'] }} stuck</span>
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
                                    'aging' => 'text-amber-300',
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
                                                @if(! empty($cell['sla_minutes']))
                                                    · target ≤{{ $cell['sla_minutes'] }}m
                                                @endif
                                                @if($cell['duration_ms'] !== null)
                                                    · last ran {{ number_format($cell['duration_ms'] / 1000, 1) }}s
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-white/40 text-xs">{{ $cell['detail'] }}</div>
                                        @endif
                                        @if(in_array($cell['status'], ['queued', 'running', 'stuck', 'aging'], true) && ($cell['last_success_at'] || $cell['status'] === 'aging'))
                                            <div class="text-white/50 text-xs mt-1">{{ $cell['detail'] }}</div>
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
</div>
