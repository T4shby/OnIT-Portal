@php
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden')->values();
    $hero = $overview['hero'] ?? [];
    $activity = $overview['activity'] ?? [];
    $monthCompare = $overview['month_compare'] ?? [];
    $period = $overview['period_label'] ?? '';
    $clientName = $user->client?->name ?? 'Your organisation';

    $toneDot = function (string $tone): string {
        return match ($tone) {
            'ok' => '#22C55E',
            'warn' => '#FACC15',
            'bad' => '#EF4444',
            default => '#64748B',
        };
    };

    $valueStats = [
        [
            'label' => 'Threats stopped',
            'value' => null,
            'sub' => null,
            'delta' => null,
            'pipeline' => 'Needs Huntress period aggregation (incidents blocked this month). Not set up yet.',
        ],
        [
            'label' => 'Tickets resolved',
            'value' => data_get($cols->firstWhere('key', 'superops'), 'metrics'),
            'pipeline' => null,
        ],
        [
            'label' => 'SLA met',
            'value' => null,
            'pipeline' => null,
        ],
    ];

    // Pull real SuperOps numbers for value strip where available
    $super = $cols->firstWhere('key', 'superops') ?? null;
    $hunt = $cols->firstWhere('key', 'huntress') ?? null;
    $resolved = null;
    $sla = null;
    $threats = null;
    if (is_array($super)) {
        foreach (($super['metrics'] ?? []) as $m) {
            if (($m['label'] ?? '') === 'Resolved (30d)' && ($m['kind'] ?? '') === 'ok') {
                $resolved = $m['value'];
            }
            if (($m['label'] ?? '') === 'Resolved this month' && ($m['kind'] ?? '') === 'ok') {
                $resolved = $m['value'];
            }
            if (($m['label'] ?? '') === 'SLA met' && ($m['kind'] ?? '') === 'ok') {
                $sla = $m['value'];
            }
        }
    }
    if (is_array($hunt)) {
        foreach (($hunt['metrics'] ?? []) as $m) {
            if (($m['label'] ?? '') === 'Resolved incidents' && ($m['kind'] ?? '') === 'ok') {
                $threats = $m['value']; // best available stand-in; still not MTD
            }
        }
    }

    $valueStrip = [
        [
            'label' => 'Threats stopped',
            'value' => $threats,
            'note' => $threats !== null ? 'resolved incidents (snapshot)' : null,
            'pipeline' => $threats === null
                ? 'Month-to-date “threats stopped” needs Huntress period stats — not set up yet.'
                : 'Snapshot total, not MTD “vs last month”. MoM comparison not set up yet.',
        ],
        [
            'label' => 'Tickets resolved',
            'value' => $resolved,
            'note' => $resolved !== null ? 'last 30 days' : null,
            'pipeline' => $resolved === null
                ? 'Needs SuperOps closed-ticket totals — product may be setup/cold.'
                : 'Avg first response and “vs last month” still not set up.',
        ],
        [
            'label' => 'SLA met',
            'value' => $sla,
            'note' => $sla !== null ? null : null,
            'pipeline' => $sla === null
                ? 'SLA % not in snapshot, or SuperOps not live. MoM compare not set up yet.'
                : '“Same as last month” compare not set up yet.',
        ],
    ];
@endphp

{{-- Match mockup 1a: full-bleed dark glance (Poppins, traffic lights, value stats, 4 columns) --}}
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    .glance { font-family: 'Poppins', system-ui, sans-serif; color: #fff; }
    .glance-label { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #FF7000; }
    .glance-muted { color: rgba(255,255,255,.65); }
    .glance-card { background: #0a2537; border: 1px solid #1F2933; border-radius: 8px; }
    .glance-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: #0a2537; border: 1px solid #1F2933; border-radius: 4px; font-size: 12px; font-weight: 500; }
    .glance-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
    .glance-svc { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border: 1px solid #1F2933; border-radius: 4px; font-size: 12px; }
    .glance-metric { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; }
    .glance-metric-label { color: rgba(255,255,255,.65); }
    .glance-metric-value { font-weight: 600; text-align: right; }
    .glance-divider { height: 1px; background: #1F2933; }
    .glance-link { font-size: 12px; font-weight: 600; color: #FF7000; }
    .glance-link:hover { color: #ff8a33; }
    .glance-toggle { display: inline-flex; background: #0a2537; border: 1px solid #1F2933; border-radius: 4px; overflow: hidden; }
    .glance-toggle span { padding: 8px 16px; font-size: 12px; }
    .glance-pipeline { font-size: 11px; color: #FACC15; font-weight: 500; }
</style>

<x-app-layout title="Dashboard" content-class="max-w-[90rem]">
<div class="glance -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-10 pb-4">

    {{-- Hero --}}
    <div class="border-b border-[#1F2933] pb-8 pt-2">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="glance-label mb-3">Your IT at a glance</div>
                <h1 class="m-0 text-[1.75rem] font-bold leading-tight tracking-tight sm:text-4xl">
                    {{ $hero['status_line'] ?? 'All systems protected.' }}
                </h1>
                <p class="mt-2.5 text-sm glance-muted">
                    {{ $period }}
                    · {{ $clientName }}
                </p>
            </div>
            <div class="glance-toggle shrink-0 self-start" title="Last-month mode needs history snapshots">
                <span class="bg-onit font-semibold text-white">This month</span>
                <span class="font-medium glance-muted cursor-not-allowed" title="{{ $monthCompare['message'] ?? 'History not set up' }}">Last month</span>
            </div>
        </div>

        {{-- Traffic lights --}}
        <div class="mt-7 flex flex-wrap gap-3">
            @foreach($cols as $col)
                @php
                    $dot = match ($col['tone'] ?? 'neutral') {
                        'ok' => '#22C55E',
                        'warn' => '#FACC15',
                        'bad' => '#EF4444',
                        'muted' => '#64748B',
                        default => '#FACC15',
                    };
                    $short = match ($col['state'] ?? '') {
                        'live' => $col['status_label'] ?? 'Live',
                        'not_sold' => 'Not sold',
                        'setup_needed' => 'Setup needed',
                        'platform' => 'Platform off',
                        'cold' => 'Never loaded',
                        'loading' => 'Loading',
                        'error' => 'Error',
                        default => $col['status_label'] ?? '—',
                    };
                @endphp
                <div class="glance-pill">
                    <span class="glance-dot" style="background:{{ $dot }}"></span>
                    <span>{{ $col['title'] }}</span>
                    <span class="text-[11px] glance-muted">{{ $short }}</span>
                </div>
            @endforeach
        </div>

        {{-- Value stats --}}
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach($valueStrip as $stat)
                <div class="glance-card px-6 py-5">
                    <div class="text-[11px] font-semibold uppercase tracking-widest glance-muted">{{ $stat['label'] }}</div>
                    <div class="mt-2 flex flex-wrap items-baseline gap-2.5">
                        @if($stat['value'] !== null)
                            <span class="text-[2.125rem] font-bold leading-none">{{ $stat['value'] }}</span>
                            @if(! empty($stat['note']))
                                <span class="text-xs glance-muted">{{ $stat['note'] }}</span>
                            @endif
                        @else
                            <span class="text-[1.35rem] font-bold leading-none text-amber-300">Not set up</span>
                        @endif
                    </div>
                    @if(! empty($stat['pipeline']))
                        <p class="mt-2 text-[11px] leading-snug text-amber-200/90">{{ $stat['pipeline'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Services strip --}}
    <div class="flex flex-col gap-3 border-b border-[#1F2933] bg-[#01131d] py-4 sm:flex-row sm:items-center sm:gap-5 -mx-4 sm:-mx-6 lg:-mx-10 px-4 sm:px-6 lg:px-10">
        <div class="text-[11px] font-semibold uppercase tracking-widest glance-muted shrink-0">Your services · managed plan</div>
        <div class="flex flex-wrap gap-2.5">
            @foreach($cols as $col)
                @php
                    $d = match ($col['tone'] ?? 'neutral') {
                        'ok' => '#22C55E',
                        'warn' => '#FACC15',
                        'bad' => '#EF4444',
                        default => '#64748B',
                    };
                @endphp
                <span class="glance-svc">
                    <span class="glance-dot" style="background:{{ $d }}"></span>
                    {{ $col['plan_label'] ?? $col['title'] }}
                </span>
            @endforeach
        </div>
    </div>

    {{-- Four columns --}}
    <div class="grid grid-cols-1 gap-4 py-7 md:grid-cols-2 xl:grid-cols-4">
        @foreach($cols as $col)
            @include('dashboard.partials._glance-column', ['col' => $col])
        @endforeach
    </div>

    {{-- Activity --}}
    <div class="pb-6">
        <div class="glance-card px-6 py-6 sm:px-7">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <div class="text-sm font-semibold">What we've done for you</div>
                <span class="glance-pipeline">Full history not set up</span>
            </div>
            <div class="border border-amber-400/25 bg-amber-400/5 px-4 py-4 rounded">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-200">Activity feed not set up yet</p>
                <p class="mt-2 text-[12.5px] leading-relaxed glance-muted">
                    {{ $activity['message'] ?? 'Cross-product event pipeline required.' }}
                </p>
                <p class="mt-3 text-[12px] leading-relaxed text-amber-100/80">
                    Mocked rows from the design (Huntress remediations, ticket resolves, backup retries) will appear here once the pipeline lands — they will not be faked as live data.
                </p>
            </div>
        </div>
    </div>

    @if(isset($portalLinks) && $portalLinks->isNotEmpty())
        <div class="pb-8">
            <div class="glance-label mb-3">Your portals</div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </div>
    @endif

</div>
</x-app-layout>
