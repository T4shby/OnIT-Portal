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
    $freshness = $pipeline['freshness'] ?? [];
    $freshInterval = $prewarm['interval_minutes']
        ?? ($freshness['interval_minutes'] ?? 2.5);
    $freshLabel = $freshness['label'] ?? null;
    $customerSessions = $freshness['customer_sessions'] ?? null;
    $configured = $freshness['configured'] ?? [];
    $fmtMin = static fn (float|int|string|null $v): string => rtrim(rtrim(number_format((float) ($v ?? 0), 1), '0'), '.');
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
    data-fresh-interval="{{ $fmtMin($freshInterval) }}"
    data-fresh-label="{{ $freshLabel ?? '' }}"
    data-customer-sessions="{{ $customerSessions ?? '' }}"
    data-cfg-hot="{{ $fmtMin($configured['hot_minutes'] ?? 2.5) }}"
    data-cfg-work-idle="{{ $fmtMin($configured['work_idle_minutes'] ?? 60) }}"
    data-cfg-off-idle="{{ $fmtMin($configured['off_hours_idle_minutes'] ?? 60) }}"
    data-cfg-presence="{{ $fmtMin($configured['presence_minutes'] ?? 15) }}"
    data-cfg-start="{{ $configured['work_start'] ?? '07:00' }}"
    data-cfg-end="{{ $configured['work_end'] ?? '19:00' }}"
    data-cfg-tz="{{ $configured['timezone'] ?? 'Europe/London' }}"
>
    {{-- Status summary — accent line, not a plate --}}
    <div class="mb-10 border-l-2 {{ $severity === 'critical' ? 'border-rose-400' : ($severity === 'warning' ? 'border-amber-400' : ($severity === 'info' ? 'border-sky-400' : 'border-onit')) }} py-1 pl-5 sm:pl-6">
        <p class="portal-label mb-3">What is going on</p>
        <p class="font-condensed text-lg font-bold leading-snug text-white sm:text-xl">{{ $headline }}</p>
        <p class="mt-4 max-w-3xl text-sm font-light leading-relaxed text-white/60">
            <span id="ih-current-target">
                Auto-refresh targets every {{ $fmtMin($freshInterval) }} minutes
                @if($freshLabel)
                    — {{ $freshLabel }}@if($customerSessions !== null) · {{ $customerSessions }} customer session(s)@endif.
                @else
                    .
                @endif
            </span>
        </p>
        <p id="ih-configured-timing" class="mt-3 max-w-3xl text-sm font-light leading-relaxed text-white/55">
            Timing settings:
            Fast {{ $fmtMin($configured['hot_minutes'] ?? 2.5) }}m
            · Idle business {{ $fmtMin($configured['work_idle_minutes'] ?? 60) }}m
            · Idle outside {{ $fmtMin($configured['off_hours_idle_minutes'] ?? 60) }}m
            · Active session {{ $fmtMin($configured['presence_minutes'] ?? 15) }}m
            · Hours {{ $configured['work_start'] ?? '07:00' }}–{{ $configured['work_end'] ?? '19:00' }}
            {{ $configured['timezone'] ?? 'Europe/London' }}
        </p>
        <p class="mt-3 max-w-3xl text-sm font-light leading-relaxed text-white/50">
            Queue workers run every minute.
            This page updates every 5 seconds
            @if($generatedAt)
                · last poll {{ $generatedAt->timezone('Europe/London')->format('H:i:s') }} UK
            @endif
        </p>
    </div>

    <div class="mb-10 grid grid-cols-1 gap-5 sm:grid-cols-3 sm:gap-6">
        <x-card class="!p-5 sm:!p-6">
            <p class="admin-stat-label">1. Scheduler (cron)</p>
            @if(! empty($scheduler['never']))
                <p class="mt-3 text-2xl font-condensed font-bold text-rose-400">Not ticking</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">Minute job has never reported in.</p>
            @elseif(! empty($scheduler['ok']))
                <p class="mt-3 text-2xl font-condensed font-bold text-emerald-400">OK</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">Last tick {{ $scheduler['age_minutes'] ?? 0 }}m ago</p>
            @else
                <p class="mt-3 text-2xl font-condensed font-bold text-rose-400">Late</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">Last tick {{ $scheduler['age_minutes'] ?? '?' }}m ago (want under 2m)</p>
            @endif
        </x-card>
        <x-card class="!p-5 sm:!p-6">
            <p class="admin-stat-label">2. Auto-refresh (prewarm)</p>
            @if(! empty($prewarm['never_ran']))
                <p class="mt-3 text-2xl font-condensed font-bold text-rose-400">Never ran</p>
            @elseif(! empty($prewarm['ok']))
                <p class="mt-3 text-2xl font-condensed font-bold text-emerald-400">OK</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">
                    {{ $prewarm['age_minutes'] ?? 0 }}m ago
                    · critical {{ $prewarm['superops_queued'] ?? 0 }}
                    · optional {{ $prewarm['optional_queued'] ?? 0 }}
                    @if(! empty($prewarm['queue_deep']))
                        · optional skipped (queue deep)
                    @endif
                </p>
            @else
                <p class="mt-3 text-2xl font-condensed font-bold text-amber-300">Late</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">
                    Last run {{ $prewarm['age_minutes'] ?? '?' }}m ago (want every ~{{ rtrim(rtrim(number_format((float) ($freshInterval ?? 2.5), 1), '0'), '.') }}m).
                </p>
            @endif
        </x-card>
        <x-card class="!p-5 sm:!p-6">
            <p class="admin-stat-label">3. Workers (process jobs)</p>
            @if(! empty($workers['lag_suspect']))
                <p class="mt-3 text-2xl font-condensed font-bold text-rose-400">Not draining</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">{{ $q['pending'] }} waiting, 0 reserved</p>
            @elseif(($q['pending'] ?? 0) > 0)
                <p class="mt-3 text-2xl font-condensed font-bold text-onit">Working</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">
                    {{ $q['pending'] }} waiting · {{ $q['reserved'] ?? 0 }} running
                </p>
            @else
                <p class="mt-3 text-2xl font-condensed font-bold text-emerald-400">Idle</p>
                <p class="mt-3 text-sm font-light leading-relaxed text-white/55">Queue empty · nothing to process</p>
            @endif
        </x-card>
    </div>

    @if(count($notices) > 0)
        <div class="admin-table-wrap mb-4 sm:mb-6">
            <div class="border-b border-white/10 px-4 sm:px-6 py-3">
                <h2 class="admin-section-title mb-0">Action list</h2>
            </div>
            <ul class="px-4 sm:px-6 py-4 space-y-2.5 text-xs sm:text-sm">
                @foreach($notices as $notice)
                    <li class="flex gap-2 leading-relaxed {{ str_contains($notice, 'Nothing blocking') ? 'text-emerald-400' : 'text-white/80' }}">
                        <span class="text-onit shrink-0">•</span>
                        <span class="min-w-0 break-words">{{ $notice }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(! empty($q['jobs']))
        <div class="admin-table-wrap mb-4 sm:mb-6">
            <div class="border-b border-white/10 px-4 sm:px-6 py-3">
                <h2 class="admin-section-title mb-0">Jobs running / waiting right now</h2>
            </div>

            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-white/5">
                @foreach($q['jobs'] as $job)
                    <div class="px-4 py-3 space-y-1">
                        <p class="text-sm text-white font-medium leading-snug break-words">{{ class_basename($job['job']) }}</p>
                        <p class="text-xs text-white/50">
                            Client #{{ $job['client_id'] ?? '—' }}
                            · {{ $job['queue'] }}
                            · <span class="{{ ! empty($job['reserved']) ? 'text-onit' : 'text-sky-300' }}">
                                {{ ! empty($job['reserved']) ? 'Worker has it' : 'Waiting' }}
                            </span>
                            · <span class="{{ ($job['age_seconds'] ?? 0) >= 90 ? 'text-rose-400' : 'text-white/60' }}">{{ $job['age_seconds'] }}s</span>
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="hidden md:block overflow-x-auto px-2 pb-3">
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
        <div class="admin-table-wrap mb-4 sm:mb-6">
            <div class="border-b border-white/10 px-4 sm:px-6 py-3">
                <h2 class="admin-section-title mb-0 text-rose-300">Recent failures</h2>
            </div>
            <ul class="px-4 sm:px-6 py-4 space-y-3 text-xs sm:text-sm text-rose-200/90">
                @foreach($q['recent_failures'] as $fail)
                    <li class="leading-relaxed break-words">
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

    <div class="admin-table-wrap mb-6 sm:mb-8">
        <div class="border-b border-white/10 px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="admin-section-title mb-0">Per client</h2>
                <p class="text-xs text-white/50 mt-1 leading-snug">
                    What each feed is doing now.
                    <span class="text-emerald-400/80">Live · 5s</span>
                </p>
            </div>
        </div>

        @if(count($integrationHealth['clients']) === 0)
            <div class="px-4 sm:px-6 py-10 sm:py-12"><x-empty-state title="No active clients" /></div>
        @else
            @php
                $statusClass = [
                    'ok' => 'text-emerald-400',
                    'due' => 'text-sky-300',
                    'aging' => 'text-amber-300',
                    'cold' => 'text-amber-300',
                    'queued' => 'text-sky-300',
                    'running' => 'text-onit',
                    'stuck' => 'text-rose-400',
                    'failed' => 'text-rose-400',
                    'disabled' => 'text-white/30',
                ];
                $feedKeys = $integrationHealth['feed_columns']
                    ?? \App\Services\Admin\IntegrationHealthService::FEED_COLUMNS;
            @endphp

            {{-- Mobile: stacked cards --}}
            <div class="md:hidden divide-y divide-white/5">
                @foreach($integrationHealth['clients'] as $row)
                    @php
                        $byKey = collect($row['integrations'])->keyBy('key');
                        $highlight = $row['is_stuck']
                            || ($row['due_count'] ?? 0) > 0
                            || ($row['aging_count'] ?? 0) > 0
                            || ($row['cold_count'] ?? 0) > 0;
                    @endphp
                    <div class="px-4 py-4 space-y-3 {{ $highlight ? 'bg-amber-500/5' : '' }}">
                        <a href="{{ route('admin.clients.edit', $row['client_id']) }}" class="text-white hover:text-onit font-medium text-sm">
                            {{ $row['client_name'] }}
                        </a>
                        <div class="space-y-2.5">
                            @foreach($feedKeys as $key => $label)
                                @php $cell = $byKey[$key] ?? null; @endphp
                                @if($cell)
                                    <div class="rounded border border-white/10 bg-[#011926]/40 px-3 py-2.5">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <span class="text-[0.65rem] font-condensed uppercase tracking-wide text-white/45">{{ $label }}</span>
                                            <span class="text-xs font-medium {{ $statusClass[$cell['status']] ?? 'text-white/60' }}">
                                                {{ $cell['status_label'] ?? strtoupper($cell['status']) }}
                                            </span>
                                        </div>
                                        @if(($cell['age_minutes'] ?? null) !== null)
                                            <p class="text-[0.7rem] text-white/45 mt-1">{{ $cell['age_minutes'] }}m since success</p>
                                        @endif
                                        <p class="text-[0.7rem] text-white/65 mt-1 leading-snug">{{ $cell['what_it_is_doing'] ?? '' }}</p>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <div class="text-xs text-white/55 pt-0.5">
                            @if($row['is_stuck'] || ($row['aging_count'] ?? 0) > 0 || ($row['due_count'] ?? 0) > 0 || ($row['cold_count'] ?? 0) > 0 || ! empty($row['blockers']))
                                <ul class="space-y-1 list-disc list-inside">
                                    @foreach(array_slice($row['blockers'] ?? [], 0, 3) as $blocker)
                                        <li class="leading-snug">{{ $blocker }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-emerald-400/80">Healthy</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table — columns from IntegrationHealthService::FEED_COLUMNS --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-[80rem] w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">Client</th>
                            @foreach($feedKeys as $label)
                                <th class="text-left">{{ $label }}</th>
                            @endforeach
                            <th class="text-left">Needs attention</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($integrationHealth['clients'] as $row)
                            @php
                                $byKey = collect($row['integrations'])->keyBy('key');
                                $highlight = $row['is_stuck']
                                    || ($row['due_count'] ?? 0) > 0
                                    || ($row['aging_count'] ?? 0) > 0
                                    || ($row['cold_count'] ?? 0) > 0;
                            @endphp
                            <tr class="{{ $highlight ? 'bg-amber-500/5' : '' }}">
                                <td class="align-top">
                                    <a href="{{ route('admin.clients.edit', $row['client_id']) }}" class="text-white hover:text-onit font-medium">
                                        {{ $row['client_name'] }}
                                    </a>
                                </td>
                                @foreach(array_keys($feedKeys) as $key)
                                    @php $cell = $byKey[$key] ?? null; @endphp
                                    @if(! $cell)
                                        <td class="align-top text-white/30">—</td>
                                    @else
                                    <td class="align-top {{ $statusClass[$cell['status']] ?? 'text-white/60' }} max-w-[14rem]">
                                        <div class="font-medium">{{ $cell['status_label'] ?? strtoupper($cell['status']) }}</div>
                                        @if(($cell['age_minutes'] ?? null) !== null)
                                            <div class="text-white/50 text-xs mt-1">{{ $cell['age_minutes'] }}m since success</div>
                                        @endif
                                        <div class="text-white/70 text-xs mt-1.5 leading-snug">{{ $cell['what_it_is_doing'] ?? '' }}</div>
                                        @if(in_array($cell['status'], ['due', 'aging', 'queued', 'running', 'stuck', 'failed', 'cold'], true))
                                            <div class="text-white/45 text-xs mt-1.5 leading-snug">→ {{ $cell['what_next'] ?? '' }}</div>
                                        @endif
                                    </td>
                                    @endif
                                @endforeach
                                <td class="align-top text-xs text-white/60 max-w-xs">
                                    @if($row['is_stuck'] || ($row['aging_count'] ?? 0) > 0 || ($row['due_count'] ?? 0) > 0 || ($row['cold_count'] ?? 0) > 0 || ! empty($row['blockers']))
                                        <ul class="space-y-1.5 list-disc list-inside leading-snug">
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
