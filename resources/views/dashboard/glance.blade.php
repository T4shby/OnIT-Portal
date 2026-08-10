@php
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden')->values();
    $hero = $overview['hero'] ?? [];
    $activity = $overview['activity'] ?? [];
    $monthCompare = $overview['month_compare'] ?? [];
    $period = $overview['period_label'] ?? '';
    $clientName = $user->client?->name ?? 'Your organisation';

    $okMetric = function (?array $col, string|array $labels) {
        if (! is_array($col)) {
            return null;
        }
        $labels = (array) $labels;
        foreach ($col['metrics'] ?? [] as $m) {
            if (($m['kind'] ?? '') !== 'ok') {
                continue;
            }
            if (in_array($m['label'] ?? '', $labels, true)) {
                return $m['value'];
            }
        }

        return null;
    };

    $super = $cols->firstWhere('key', 'superops');
    $hunt = $cols->firstWhere('key', 'huntress');
    $resolved = $okMetric($super, ['Resolved this month', 'Resolved (30d)']);
    $sla = $okMetric($super, 'SLA met');
    $threats = $okMetric($hunt, ['Remediated', 'Remediated (snapshot)', 'Resolved incidents']);

    $valueStrip = [
        [
            'label' => 'Threats stopped',
            'value' => $threats,
            'note' => $threats !== null ? 'remediated' : null,
            'empty' => '—',
        ],
        [
            'label' => 'Tickets resolved',
            'value' => $resolved,
            'note' => $resolved !== null ? 'this period' : null,
            'empty' => '—',
        ],
        [
            'label' => 'SLA met',
            'value' => $sla,
            'note' => null,
            'empty' => '—',
        ],
    ];
@endphp

{{-- Mockup 1a: live numbers only; no pipeline walls under every metric --}}
<x-app-layout title="Dashboard" content-class="max-w-[90rem]">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    .glance { font-family: 'Poppins', system-ui, sans-serif; color: #fff; }
    .glance-label { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #FF7000; }
    .glance-muted { color: rgba(255,255,255,.65); }
    .glance-card { background: #0a2537; border: 1px solid #1F2933; border-radius: 8px; }
    .glance-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: #0a2537; border: 1px solid #1F2933; border-radius: 4px; font-size: 12px; font-weight: 500; }
    .glance-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
    .glance-svc { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border: 1px solid #1F2933; border-radius: 4px; font-size: 12px; }
    .glance-metric { display: flex; justify-content: space-between; gap: 12px; font-size: 12.5px; }
    .glance-metric-label { color: rgba(255,255,255,.65); }
    .glance-metric-value { font-weight: 600; text-align: right; }
    .glance-divider { height: 1px; background: #1F2933; }
    .glance-link { font-size: 12px; font-weight: 600; color: #FF7000; text-decoration: none; }
    .glance-link:hover { color: #ff8a33; }
    .glance-toggle { display: inline-flex; background: #0a2537; border: 1px solid #1F2933; border-radius: 4px; overflow: hidden; }
    .glance-toggle span { padding: 8px 16px; font-size: 12px; }
</style>
<div class="glance" style="padding-bottom:1rem">

    <div style="border-bottom:1px solid #1F2933;padding-bottom:2rem">
        <div style="display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:space-between;align-items:flex-start">
            <div style="min-width:0;flex:1 1 16rem">
                <div class="glance-label" style="margin-bottom:12px">Your IT at a glance</div>
                <h1 style="margin:0;font-size:clamp(1.5rem,3.5vw,2.25rem);font-weight:700;line-height:1.15;letter-spacing:-0.02em">
                    {{ $hero['status_line'] ?? 'All systems protected.' }}
                </h1>
                <p style="margin:10px 0 0;font-size:14px" class="glance-muted">
                    {{ $period }} · {{ $clientName }}
                </p>
            </div>
            <div class="glance-toggle" title="{{ $monthCompare['message'] ?? 'History not set up' }}">
                <span style="background:#FF7000;font-weight:600;color:#fff">This month</span>
                <span class="glance-muted" style="font-weight:500;cursor:not-allowed">Last month</span>
            </div>
        </div>

        <div style="margin-top:1.75rem;display:flex;flex-wrap:wrap;gap:10px">
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
                    <span style="font-size:11px" class="glance-muted">{{ $short }}</span>
                </div>
            @endforeach
        </div>

        <div style="margin-top:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
            @foreach($valueStrip as $stat)
                <div class="glance-card" style="padding:1.25rem 1.5rem">
                    <div style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase" class="glance-muted">{{ $stat['label'] }}</div>
                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;align-items:baseline;gap:10px">
                        @if($stat['value'] !== null)
                            <span style="font-size:2.125rem;font-weight:700;line-height:1">{{ $stat['value'] }}</span>
                            @if(! empty($stat['note']))
                                <span style="font-size:12px" class="glance-muted">{{ $stat['note'] }}</span>
                            @endif
                        @else
                            <span style="font-size:2.125rem;font-weight:700;line-height:1;color:rgba(255,255,255,.35)">{{ $stat['empty'] }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;border-bottom:1px solid #1F2933;background:#01131d;margin:0 -1rem;padding:14px 1rem">
        <div style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;flex:none" class="glance-muted">Your services · managed plan</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
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

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;padding:1.75rem 0">
        @foreach($cols as $col)
            @include('dashboard.partials._glance-column', ['col' => $col])
        @endforeach
    </div>

    <div style="padding-bottom:1.5rem">
        <div class="glance-card" style="padding:1.35rem 1.5rem">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:10px">
                <div style="font-size:14px;font-weight:600">What we've done for you</div>
            </div>
            <p style="margin:0;font-size:13px;line-height:1.5" class="glance-muted">
                {{ $activity['message'] ?? 'Activity history not available yet.' }}
            </p>
        </div>
    </div>

    @if(isset($portalLinks) && $portalLinks->isNotEmpty())
        <div style="padding-bottom:2rem">
            <div class="glance-label" style="margin-bottom:12px">Your portals</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </div>
    @endif

</div>
</x-app-layout>
