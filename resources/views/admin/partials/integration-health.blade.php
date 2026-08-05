@php
    $q = $integrationHealth['queue'];
    $pipeline = $integrationHealth['pipeline'] ?? [];
    $notices = $integrationHealth['notices'] ?? [];
    $prewarm = $pipeline['prewarm'] ?? [];
    $workers = $pipeline['workers'] ?? [];
    $scheduler = $pipeline['scheduler'] ?? [];
    $generatedAt = $pipeline['generated_at'] ?? null;
    $severity = $pipeline['severity_level'] ?? 'ok';
    $headline = $pipeline['headline'] ?? 'Refresh health';
    $bannerClass = match ($severity) {
        'critical' => 'border-rose-400/40 bg-rose-500/10 text-rose-100',
        'warning' => 'border-amber-400/40 bg-amber-500/10 text-amber-50',
        'info' => 'border-sky-400/40 bg-sky-500/10 text-sky-50',
        default => 'border-emerald-400/30 bg-emerald-500/5 text-emerald-50',
    };
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
    {{-- Plain-English system status --}}
    <div class="mb-6 rounded-lg border px-5 py-4 {{ $bannerClass }}">
        <p class="text-xs uppercase tracking-wide opacity-70 mb-1">What is going on</p>
        <p class="text-lg font-condensed font-bold">{{ $headline }}</p>
        <p class="text-sm opacity-80 mt-2">
            Auto-refresh runs every 5 minutes. Queue workers run every minute.
            This page updates every 5 seconds
            @if($generatedAt)
                · last poll {{ $generatedAt->timezone('Europe/London')->format('H:i:s') }} UK
            @endif
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <x-card>
            <p class="admin-stat-label">1. Scheduler (cron)</p>
            @if(! empty($scheduler['never']))
                <p class="text-2xl font-condensed font-bold text-rose-400">Not ticking</p>
                <p class="text-sm text-white/60 mt-2">Minute job has never reported in.</p>
            @elseif(! empty($scheduler['ok']))
                <p class="text-2xl font-condensed font-bold text-emerald-400">OK</p>
                <p class="text-sm text-white/60 mt-2">Last tick {{ $scheduler['age_minutes'] ?? 0 }}m ago</p>
            @else
                <p class="text-2xl font-condensed font-bold text-rose-400">Late</p>
                <p class="text-sm text-white/60 mt-2">Last tick {{ $scheduler['age_minutes'] ?? '?' }}m ago (want under 2m)</p>
            @endif
        </x-card>
        <x-card>
            <p class="admin-stat-label">2. Auto-refresh (prewarm)</p>
            @if(! empty($prewarm['never_ran']))
                <p class="text-2xl font-condensed font-bold text-rose-400">Never ran</p>
            @elseif(! empty($prewarm['ok']))
                <p class="text-2xl font-condensed font-bold text-emerald-400">OK</p>
                <p class="text-sm text-white/60 mt-2">
                    {{ $prewarm['age_minutes'] ?? 0 }}m ago · queued SuperOps {{ $prewarm['superops_queued'] ?? 0 }}
                </p>
            @else
                <p class="text-2xl font-condensed font-bold text-amber-300">Late</p>
                <p class="text-sm text-white/60 mt-2">
                    Last run {{ $prewarm['age_minutes'] ?? '?' }}m ago (want every 5m).
                    Data ages until this runs again.
                </p>
            @endif
        </x-card>
        <x-card>
            <p class="admin-stat-label">3. Workers (process jobs)</p>
            @if(! empty($workers['lag_suspect']))
                <p class="text-2xl font-condensed font-bold text-rose-400">Not draining</p>
                <p class="text-sm text-white/60 mt-2">{{ $q['pending'] }} waiting, 0 reserved</p>
            @elseif(($q['pending'] ?? 0) > 0)
                <p class="text-2xl font-condensed font-bold text-onit">Working</p>
                <p class="text-sm text-white/60 mt-2">
                    {{ $q['pending'] }} waiting · {{ $q['reserved'] ?? 0 }} running
                </p>
            @else
                <p class="text-2xl font-condensed font-bold text-emerald-400">Idle</p>
                <p class="text-sm text-white/60 mt-2">Queue empty · nothing to process</p>
            @endif
        </x-card>
    </div>

    @if(count($notices) > 0)
        <div class="admin-table-wrap mb-6">
            <div class="border-b border-white/10 px-6 py-3">
                <h2 class="admin-section-title mb-0">Action list</h2>
            </div>
            <ul class="px-6 py-4 space-y-2 text-sm">
                @foreach($notices as $notice)
                    <li class="flex gap-2 {{ str_contains($notice, 'Nothing blocking') ? 'text-emerald-400' : 'text-white/80' }}">
                        <span class="text-onit shrink-0">•</span>
                        <span>{{ $notice }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(! empty($q['jobs']))
        <div class="admin-table-wrap mb-6">
            <div class="border-b border-white/10 px-6 py-3">
                <h2 class="admin-section-title mb-0">Jobs running / waiting right now</h2>
            </div>
            <div class="overflow-x-auto px-2 pb-3">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-white/50 text-xs">
                            <th class="text-left px-4 py-2">What</th>
                            <th class="text-left px-4 py-2">Client</th>
                            <th class="text-left px-4 py-2">Queue</th>
                            <th class="text-left px-4 py-2">State</th>
                            <th class="text-left px-4 py-2">Waiting</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($q['jobs'] as $job)
                            <tr class="border-t border-white/5">
                                <td class="px-4 py-2 text-white">{{ class_basename($job['job']) }}</td>
                                <td class="px-4 py-2">#{{ $job['client_id'] ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $job['queue'] }}</td>
                                <td class="px-4 py-2 {{ ! empty($job['reserved']) ? 'text-onit' : 'text-sky-300' }}">
                                    {{ ! empty($job['reserved']) ? 'Worker has it' : 'Waiting for worker' }}
                                </td>
                                <td class="px-4 py-2 {{ ($job['age_seconds'] ?? 0) >= 90 ? 'text-rose-400' : '' }}">
                                    {{ $job['age_seconds'] }}s
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if(! empty($q['recent_failures']))
        <div class="admin-table-wrap mb-6">
            <div class="border-b border-white/10 px-6 py-3">
                <h2 class="admin-section-title mb-0 text-rose-300">Recent failures</h2>
            </div>
            <ul class="px-6 py-4 space-y-2 text-sm text-rose-200/90">
                @foreach($q['recent_failures'] as $fail)
                    <li>
                        {{ class_basename($fail['job']) }}
                        @if($fail['client_id']) · client #{{ $fail['client_id'] }} @endif
                        @if($fail['failed_at'])
                            · {{ $fail['failed_at']->timezone('Europe/London')->format('d M H:i') }} UK
                        @endif
                        — {{ $fail['error'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-table-wrap mb-8">
        <div class="border-b border-white/10 px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="admin-section-title mb-0">Per client</h2>
                <p class="text-xs text-white/50 mt-1">
                    What each feed is doing now — not raw flags.
                    <span class="text-emerald-400/80">Live · 5s</span>
                </p>
            </div>
            <a href="{{ route('admin.integration-health.index') }}" class="text-xs text-onit hover:text-white uppercase tracking-wide">Reload</a>
        </div>

        @if(count($integrationHealth['clients']) === 0)
            <div class="px-6 py-12"><x-empty-state title="No active clients" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">Client</th>
                            <th class="text-left">Devices & tickets</th>
                            <th class="text-left">M365 people</th>
                            <th class="text-left">M365 licences</th>
                            <th class="text-left">Entra sync</th>
                            <th class="text-left">Needs attention</th>
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
                                </td>
                                @foreach(['superops', 'm365_directory', 'm365_insights', 'entra_sync'] as $key)
                                    @php $cell = $byKey[$key]; @endphp
                                    <td class="align-top {{ $statusClass[$cell['status']] ?? 'text-white/60' }} max-w-[14rem]">
                                        <div class="font-medium">{{ $cell['status_label'] ?? strtoupper($cell['status']) }}</div>
                                        @if(($cell['age_minutes'] ?? null) !== null)
                                            <div class="text-white/50 text-xs mt-0.5">{{ $cell['age_minutes'] }}m since success</div>
                                        @endif
                                        <div class="text-white/70 text-xs mt-1">{{ $cell['what_it_is_doing'] ?? '' }}</div>
                                        @if(in_array($cell['status'], ['due', 'aging', 'queued', 'running', 'stuck', 'failed', 'cold'], true))
                                            <div class="text-white/50 text-xs mt-1">→ {{ $cell['what_next'] ?? '' }}</div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="align-top text-xs text-white/60 max-w-xs">
                                    @if($row['is_stuck'] || ($row['aging_count'] ?? 0) > 0 || ($row['due_count'] ?? 0) > 0 || ! empty($row['blockers']))
                                        <ul class="space-y-1 list-disc list-inside">
                                            @foreach(array_slice($row['blockers'] ?? [], 0, 3) as $blocker)
                                                <li>{{ $blocker }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span class="text-emerald-400/80">Healthy</span>
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
