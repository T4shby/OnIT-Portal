@php
    $q = $integrationHealth['queue'];
    $pipeline = $integrationHealth['pipeline'] ?? [];
    $notices = $integrationHealth['notices'] ?? [];
    $prewarm = $pipeline['prewarm'] ?? [];
    $workers = $pipeline['workers'] ?? [];
    $generatedAt = $pipeline['generated_at'] ?? null;
@endphp
<div
    id="integration-health-live"
    data-queue-pending="{{ $q['pending'] }}"
    data-queue-high="{{ $q['high'] }}"
    data-queue-default="{{ $q['default'] }}"
    data-queue-failed="{{ $q['failed'] }}"
    data-queue-oldest="{{ $q['oldest_pending_seconds'] ?? '' }}"
    data-stuck-count="{{ $integrationHealth['stuck_count'] }}"
    data-due-count="{{ $integrationHealth['due_count'] ?? 0 }}"
    data-aging-count="{{ $integrationHealth['aging_count'] ?? 0 }}"
>
    <div class="admin-table-wrap mb-8">
        <div class="border-b border-white/10 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="admin-section-title mb-0">Integration refresh health</h2>
                <p class="text-xs text-white/50 mt-1">
                    Technician pipeline — flags, jobs table, prewarm heartbeat, blockers.
                    SuperOps requeue ≥{{ (int) ($pipeline['superops_requeue_after_minutes'] ?? config('services.superops.dashboard_refresh_after_minutes', 10)) }}m ·
                    client note ≥{{ (int) ($pipeline['superops_client_window_minutes'] ?? config('services.superops.dashboard_cache_minutes', 15)) }}m.
                    <span class="text-emerald-400/80">Live · 5s</span>
                    @if(($integrationHealth['stuck_count'] ?? 0) > 0)
                        <span class="text-amber-300"> · {{ $integrationHealth['stuck_count'] }} stuck</span>
                    @endif
                    @if(($integrationHealth['due_count'] ?? 0) > 0)
                        <span class="text-sky-300"> · {{ $integrationHealth['due_count'] }} due</span>
                    @endif
                    @if(($integrationHealth['aging_count'] ?? 0) > 0)
                        <span class="text-amber-300"> · {{ $integrationHealth['aging_count'] }} aging</span>
                    @endif
                    @if($generatedAt)
                        <span class="text-white/30"> · polled {{ $generatedAt->timezone('Europe/London')->format('H:i:s') }} UK</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="text-xs text-onit hover:text-white uppercase tracking-wide">Reload</a>
        </div>

        {{-- Pipeline board --}}
        <div class="border-b border-white/10 px-6 py-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div>
                <p class="portal-label mb-2">Prewarm heartbeat</p>
                @if(! empty($prewarm['never_ran']))
                    <p class="text-rose-400 font-medium">Never recorded</p>
                    <p class="text-white/50 mt-1">Scheduler may not run <code class="text-white/40">portal:prewarm-client-dashboards</code>.</p>
                @else
                    <p class="{{ ! empty($prewarm['overdue']) ? 'text-amber-300' : 'text-emerald-400' }} font-medium">
                        Last run
                        @if(! empty($prewarm['last_at']))
                            {{ $prewarm['last_at']->timezone('Europe/London')->format('d M H:i:s') }} UK
                        @endif
                        · {{ $prewarm['age_minutes'] ?? '?' }}m ago
                    </p>
                    <p class="text-white/50 mt-1">
                        Queued that run: SuperOps {{ $prewarm['superops_queued'] ?? '?' }}
                        · other {{ $prewarm['optional_queued'] ?? '?' }}
                        · clients {{ $prewarm['clients'] ?? '?' }}
                        · pending before {{ $prewarm['pending_before'] ?? '?' }}
                        @if(! empty($prewarm['queue_deep']))
                            <span class="text-amber-300"> · queue deep (optional skipped)</span>
                        @endif
                    </p>
                @endif
            </div>
            <div>
                <p class="portal-label mb-2">Queue workers</p>
                <p class="{{ ! empty($workers['lag_suspect']) ? 'text-rose-400' : 'text-white/80' }} font-medium">
                    pending {{ $q['pending'] }}
                    · reserved {{ $q['reserved'] ?? 0 }}
                    · high {{ $q['high'] }}
                    · default {{ $q['default'] }}
                    · failed {{ $q['failed'] }}
                </p>
                <p class="text-white/50 mt-1">
                    @if(($q['oldest_pending_seconds'] ?? null) !== null)
                        Oldest waiting {{ number_format($q['oldest_pending_seconds'] / 60, 1) }}m.
                    @else
                        Queue empty.
                    @endif
                    @if(! empty($workers['lag_suspect']))
                        <span class="text-rose-400 font-medium"> Worker lag: jobs idle with nothing reserved.</span>
                    @endif
                </p>
                <p class="text-white/30 mt-1">{{ $workers['expect'] ?? '' }}</p>
            </div>
            <div>
                <p class="portal-label mb-2">Why isn't it resetting?</p>
                <ul class="space-y-1 text-white/70 list-disc list-inside">
                    @foreach($notices as $notice)
                        <li class="{{ str_contains($notice, 'No pipeline blockers') ? 'text-emerald-400/90 list-none -ml-4' : '' }}">
                            {{ $notice }}
                        </li>
                    @endforeach
                </ul>
                @if(($integrationHealth['cleared_orphans'] ?? 0) > 0)
                    <p class="text-sky-300 mt-2">Orphans cleared this poll: {{ $integrationHealth['cleared_orphans'] }}</p>
                @endif
            </div>
        </div>

        {{-- Live jobs table --}}
        @if(! empty($q['jobs']))
            <div class="border-b border-white/10 px-6 py-4">
                <p class="portal-label mb-2">Jobs table (live) — {{ count($q['jobs']) }} shown</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="text-white/40">
                                <th class="text-left py-1 pr-3">ID</th>
                                <th class="text-left py-1 pr-3">Queue</th>
                                <th class="text-left py-1 pr-3">Job</th>
                                <th class="text-left py-1 pr-3">Client</th>
                                <th class="text-left py-1 pr-3">Age</th>
                                <th class="text-left py-1 pr-3">State</th>
                                <th class="text-left py-1">Attempts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($q['jobs'] as $job)
                                <tr class="border-t border-white/5">
                                    <td class="py-1 pr-3 text-white/50">{{ $job['id'] }}</td>
                                    <td class="py-1 pr-3">{{ $job['queue'] }}</td>
                                    <td class="py-1 pr-3 text-white/80">{{ class_basename($job['job']) }}</td>
                                    <td class="py-1 pr-3">{{ $job['client_id'] ?? '—' }}</td>
                                    <td class="py-1 pr-3 {{ ($job['age_seconds'] ?? 0) >= 90 ? 'text-rose-400' : 'text-white/60' }}">
                                        {{ $job['age_seconds'] }}s
                                    </td>
                                    <td class="py-1 pr-3 {{ ! empty($job['reserved']) ? 'text-onit' : 'text-sky-300' }}">
                                        @if(! empty($job['reserved']))
                                            reserved {{ $job['reserved_for_seconds'] ?? 0 }}s
                                        @else
                                            waiting
                                        @endif
                                    </td>
                                    <td class="py-1">{{ $job['attempts'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if(! empty($q['recent_failures']))
            <div class="border-b border-white/10 px-6 py-4">
                <p class="portal-label mb-2">Recent failed_jobs</p>
                <ul class="space-y-1 text-xs text-rose-300/90">
                    @foreach($q['recent_failures'] as $fail)
                        <li>
                            #{{ $fail['id'] }}
                            {{ class_basename($fail['job']) }}
                            @if($fail['client_id'])
                                client {{ $fail['client_id'] }}
                            @endif
                            · {{ $fail['queue'] }}
                            @if($fail['failed_at'])
                                · {{ $fail['failed_at']->timezone('Europe/London')->format('d M H:i') }} UK
                            @endif
                            — {{ $fail['error'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

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
                            <th class="text-left">Blockers / active</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($integrationHealth['clients'] as $row)
                            @php
                                $byKey = collect($row['integrations'])->keyBy('key');
                                $statusClass = [
                                    'ok' => 'text-emerald-400',
                                    'due' => 'text-sky-300',
                                    'aging' => 'text-amber-300',
                                    'cold' => 'text-white/40',
                                    'queued' => 'text-sky-300',
                                    'running' => 'text-onit',
                                    'stuck' => 'text-rose-400',
                                    'failed' => 'text-rose-400',
                                    'disabled' => 'text-white/30',
                                ];
                                $highlight = $row['is_stuck']
                                    || ($row['due_count'] ?? 0) > 0
                                    || ($row['aging_count'] ?? 0) > 0;
                            @endphp
                            <tr class="{{ $highlight ? 'bg-amber-500/5' : '' }}">
                                <td class="align-top">
                                    <a href="{{ route('admin.clients.edit', $row['client_id']) }}" class="text-white hover:text-onit font-medium">
                                        {{ $row['client_name'] }}
                                    </a>
                                    <div class="text-white/30 text-[10px] mt-1">id {{ $row['client_id'] }}</div>
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
                                                @if(! empty($cell['requeue_after_minutes']))
                                                    · rq ≥{{ $cell['requeue_after_minutes'] }}m
                                                @endif
                                                @if(! empty($cell['sla_minutes']))
                                                    · client ≤{{ $cell['sla_minutes'] }}m
                                                @endif
                                                @if($cell['duration_ms'] !== null)
                                                    · ran {{ number_format($cell['duration_ms'] / 1000, 1) }}s
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-white/40 text-xs">{{ $cell['detail'] }}</div>
                                        @endif
                                        <div class="text-white/30 text-[10px] mt-1 font-mono">
                                            flag={{ ! empty($cell['flag_queued']) ? 'Y' : 'n' }}
                                            · job={{ ! empty($cell['job_in_db']) ? 'Y' : 'n' }}
                                            @if(! empty($cell['job_reserved']))
                                                · res
                                            @endif
                                            @if(($cell['job_age_seconds'] ?? null) !== null)
                                                · jobAge {{ $cell['job_age_seconds'] }}s
                                            @endif
                                        </div>
                                        @if(! empty($cell['last_finished_at']))
                                            <div class="text-white/25 text-[10px]">
                                                last finish {{ $cell['last_finished_at']->timezone('Europe/London')->format('H:i:s') }} UK
                                                @if(! empty($cell['error']))
                                                    · err
                                                @endif
                                            </div>
                                        @endif
                                        @if(in_array($cell['status'], ['queued', 'running', 'stuck', 'aging', 'due', 'failed'], true))
                                            <div class="text-white/50 text-xs mt-1">{{ $cell['detail'] }}</div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="align-top text-xs">
                                    @if($row['active_process'])
                                        <span class="{{ $row['is_stuck'] ? 'text-rose-400' : 'text-sky-300' }}">
                                            {{ $row['active_process'] }} · {{ $row['active_status'] }}
                                        </span>
                                    @endif
                                    @if(! empty($row['blockers']))
                                        <ul class="mt-1 space-y-1 text-white/60 list-disc list-inside max-w-xs">
                                            @foreach(array_slice($row['blockers'], 0, 4) as $blocker)
                                                <li>{{ $blocker }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif(! $row['active_process'])
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
