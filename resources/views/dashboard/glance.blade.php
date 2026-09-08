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
    $resolved = $okMetric($super, ['Tickets we closed', 'Resolved this month', 'Resolved (30d)']);
    $sla = $okMetric($super, ['Resolved on time', 'SLA met']);
    $openTickets = $okMetric($super, 'Open tickets');
    $threats = $okMetric($hunt, ['Threats stopped', 'Threats stopped (MTD)', 'Remediated', 'Remediated (snapshot)', 'Resolved incidents']);
    $huntLive = is_array($hunt) && ($hunt['state'] ?? '') === 'live';
    $huntSold = is_array($hunt) && ! in_array($hunt['state'] ?? '', ['not_sold', 'hidden'], true);

    /*
     * Value strip: never lead with empty Threats stopped when MDR is not sold -
     * that reads as zero protection. Support-only orgs get support metrics.
     */
    if ($huntLive && $threats !== null) {
        $valueStrip = [
            [
                'label' => 'Threats stopped',
                'value' => $threats,
                'note' => $okMetric($hunt, ['Threats stopped', 'Threats stopped (MTD)']) !== null ? 'this month' : 'handled',
                'empty' => 'Checking',
            ],
            [
                'label' => 'Tickets we closed',
                'value' => $resolved,
                'note' => $resolved !== null ? 'this month' : null,
                'empty' => 'Checking',
            ],
            [
                'label' => 'Resolved on time',
                'value' => $sla,
                'note' => null,
                'empty' => 'Checking',
            ],
        ];
    } elseif ($huntSold) {
        $valueStrip = [
            [
                'label' => 'Threats stopped',
                'value' => $threats,
                'note' => $threats !== null ? 'this month' : null,
                'empty' => 'Checking',
            ],
            [
                'label' => 'Tickets we closed',
                'value' => $resolved,
                'note' => $resolved !== null ? 'this month' : null,
                'empty' => 'Checking',
            ],
            [
                'label' => 'Resolved on time',
                'value' => $sla,
                'note' => null,
                'empty' => 'Checking',
            ],
        ];
    } else {
        $valueStrip = [
            [
                'label' => 'Tickets we closed',
                'value' => $resolved,
                'note' => $resolved !== null ? 'this month' : null,
                'empty' => 'Checking',
            ],
            [
                'label' => 'Open tickets',
                'value' => $openTickets,
                'note' => null,
                'empty' => 'Checking',
            ],
            [
                'label' => 'Resolved on time',
                'value' => $sla,
                'note' => null,
                'empty' => 'Checking',
            ],
        ];
    }
    $monthReady = ($monthCompare['available'] ?? false) === true;
@endphp

{{-- Client home: On IT chrome, live numbers only --}}
<x-app-layout title="Dashboard" content-class="max-w-[90rem]">
<style>
    .glance { color: #fff; }
    .glance-label { font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #FF7000; font-family: 'Barlow Condensed', sans-serif; }
    .glance-muted { color: rgba(255,255,255,.65); }
    .glance-activity-badge {
        display: inline-block;
        margin-top: 6px;
        padding: 3px 8px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        color: #fff;
        background: rgba(255,112,0,.28);
        border: 1px solid #FF7000;
    }
    .glance-card { background: #071f2e; border: 1px solid #0f3048; }
    .glance-columns {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
        padding: 1.75rem 0;
        align-items: stretch;
    }
    @media (min-width: 700px) {
        .glance-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1100px) {
        .glance-columns { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .glance-card-service {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.35rem 1.4rem;
        box-sizing: border-box;
        align-self: stretch;
    }
    .glance-status { min-height: 5.25rem; }
    .glance-metrics { display: flex; flex-direction: column; gap: 10px; flex: 1 1 auto; }
    .glance-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: #071f2e; border: 1px solid #0f3048; font-size: 12px; font-weight: 500; }
    .glance-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
    .glance-svc { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border: 1px solid #0f3048; font-size: 12px; }
    .glance-metric { display: flex; justify-content: space-between; gap: 12px; font-size: 12.5px; }
    .glance-metric-label { color: rgba(255,255,255,.65); }
    .glance-metric-value { font-weight: 600; text-align: right; }
    .glance-divider { height: 1px; background: #0f3048; }
    .glance-link { font-size: 12px; font-weight: 600; color: #FF7000; text-decoration: none; }
    .glance-link:hover { color: #ff8a33; }
    .glance-toggle {
        display: inline-flex;
        background: #071f2e;
        border: 1px solid #0f3048;
        overflow: hidden;
    }
    .glance-toggle span { padding: 10px 18px; font-size: 12px; line-height: 1.2; }
    .glance-period {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 10px;
        max-width: 18rem;
        flex: 0 1 auto;
    }
    @media (max-width: 640px) {
        .glance-period { align-items: stretch; max-width: none; width: 100%; }
        .glance-toggle { width: 100%; }
        .glance-toggle > span:first-child { flex: 1; text-align: center; justify-content: center; }
        .glance-value-grid { grid-template-columns: 1fr !important; }
        .glance-columns { grid-template-columns: 1fr !important; padding-top: 1.25rem !important; }
        .glance-portals { grid-template-columns: 1fr !important; }
        .glance-services-bar {
            margin-left: -1rem !important;
            margin-right: -1rem !important;
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }
        .glance-activity-row {
            grid-template-columns: 1fr !important;
            gap: 6px !important;
        }
        .glance-activity-meta {
            text-align: left !important;
            white-space: normal !important;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
        }
        .glance-link { min-height: 44px; display: inline-flex; align-items: center; }
    }
</style>
<div class="glance" style="padding-bottom:1rem">
    <div style="border-bottom:1px solid #0f3048;padding-bottom:2rem">
        <div style="display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:space-between;align-items:flex-start">
            <div style="min-width:0;flex:1 1 16rem">
                <div class="orange-rule" style="margin-bottom:12px"></div>
                <div class="heading-stack mb-3">
                    <h1 class="section-heading-white">Your IT</h1>
                    <h1 class="section-heading-orange">at a glance</h1>
                </div>
                <p style="margin:0;font-size:clamp(1.25rem,2.5vw,1.65rem);font-weight:600;line-height:1.25;letter-spacing:-0.02em" class="font-condensed uppercase tracking-wide">
                    {{ $hero['status_line'] ?? 'Everything looks good.' }}
                </p>
                <p style="margin:10px 0 0;font-size:14px" class="glance-muted">
                    {{ $period }} · {{ $clientName }}
                </p>
                @if(! empty($hero['status_detail']))
                    <p style="margin:8px 0 0;font-size:13px;line-height:1.45;max-width:40rem" class="glance-muted">
                        {{ $hero['status_detail'] }}
                    </p>
                @endif
            </div>
            @if($monthReady)
                <div class="glance-period" role="group" aria-label="Report period">
                    <div class="glance-toggle">
                        <span style="background:#FF7000;font-weight:600;color:#fff;display:inline-flex;align-items:center">This month</span>
                        <span class="glance-muted" style="font-weight:500;display:inline-flex;align-items:center">
                            Last month
                            @if(! empty($monthCompare['as_of']))
                                <span style="opacity:.75;margin-left:.35em">({{ \Illuminate\Support\Carbon::parse($monthCompare['as_of'])->format('M j') }})</span>
                            @endif
                        </span>
                    </div>
                </div>
            @endif
        </div>

        @if($monthReady && ! empty($monthCompare['value_deltas']))
            <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:10px 16px">
                @foreach($monthCompare['value_deltas'] as $delta)
                    <div style="font-size:12px" class="glance-muted">
                        <span style="font-weight:600;color:rgba(255,255,255,.85)">{{ $delta['label'] }}</span>
                        now {{ $delta['current'] ?? 'Checking' }}
                        · then {{ $delta['previous'] ?? 'Checking' }}
                    </div>
                @endforeach
            </div>
        @endif

        <div class="portal-scroll-strip" style="margin-top:1.75rem">
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
                        'live' => $col['status_label'] ?? 'Healthy',
                        'not_sold' => $col['status_label'] ?? 'Not on plan',
                        'setup_needed' => $col['status_label'] ?? 'Getting ready',
                        'platform' => 'Temporarily unavailable',
                        'cold' => 'Checking',
                        'loading' => 'Checking',
                        'error' => 'Needs attention',
                        default => $col['status_label'] ?? 'Checking',
                    };
                @endphp
                <div class="glance-pill" title="{{ $col['status_reason'] ?? $col['status_label'] ?? '' }}">
                    <span class="glance-dot" style="background:{{ $dot }}"></span>
                    <span>{{ $col['title'] }}</span>
                    <span style="font-size:11px" class="glance-muted">{{ $short }}</span>
                </div>
            @endforeach
        </div>

        <div class="glance-value-grid" style="margin-top:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
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
                            <span style="font-size:1.25rem;font-weight:600;line-height:1.2;color:rgba(255,255,255,.45)">{{ $stat['empty'] }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="glance-services-bar" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;border-bottom:1px solid #0f3048;background:#011926;margin:0 -1rem;padding:14px 1rem">
        <div style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;flex:none;font-family:'Barlow Condensed',sans-serif" class="glance-muted">Your services</div>
        <div class="portal-scroll-strip" style="gap:8px">
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

    <div class="glance-columns">
        @foreach($cols as $col)
            @include('dashboard.partials._glance-column', ['col' => $col])
        @endforeach
    </div>

    <div style="padding-bottom:1.5rem">
        <div class="glance-card" style="padding:1.35rem 1.5rem">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:10px">
                <div style="font-size:14px;font-weight:600">What we have done for you</div>
            </div>
            @if(! empty($activity['items']))
                <div style="display:flex;flex-direction:column;gap:12px">
                    @foreach($activity['items'] as $item)
                        @php
                            $at = filled($item['at'] ?? null)
                                ? \Illuminate\Support\Carbon::parse($item['at'])->timezone('Europe/London')->format('d M · H:i')
                                : null;
                            $sourceLabel = match ($item['source'] ?? '') {
                                'support' => 'Support',
                                'security' => 'Security',
                                'backup' => 'Backup',
                                default => 'Update',
                            };
                        @endphp
                        <div class="glance-activity-row" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 12px;align-items:start">
                            <div style="min-width:0">
                                <div style="font-size:13px;font-weight:600;line-height:1.3;color:#fff">
                                    {{ $item['title'] ?? $sourceLabel }}
                                </div>
                                @if(filled($item['text'] ?? null))
                                    <div style="margin-top:2px;font-size:12.5px;line-height:1.4" class="glance-muted">
                                        {{ $item['text'] }}
                                    </div>
                                @endif
                                @if(filled($item['badge'] ?? null))
                                    <span class="glance-activity-badge">{{ $item['badge'] }}</span>
                                @endif
                            </div>
                            <div class="glance-activity-meta glance-muted" style="text-align:right;font-size:11px;white-space:nowrap">
                                @if($at){{ $at }}@endif
                                @if(filled($item['ref'] ?? null))
                                    <div style="margin-top:2px;color:#fff;font-weight:600">{{ $item['ref'] }}</div>
                                @endif
                                <div style="margin-top:2px;font-weight:500;letter-spacing:.04em;text-transform:uppercase">{{ $sourceLabel }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p style="margin:0;font-size:13px;line-height:1.5" class="glance-muted">
                    {{ $activity['message'] ?? 'Nothing new to show right now.' }}
                </p>
            @endif
        </div>
    </div>

    @if(isset($portalLinks) && $portalLinks->isNotEmpty())
        <div style="padding-bottom:2rem">
            <div class="glance-label" style="margin-bottom:12px">Your portals</div>
            <div class="glance-portals" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </div>
    @endif

</div>
</x-app-layout>
