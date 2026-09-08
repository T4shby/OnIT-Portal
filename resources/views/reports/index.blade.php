@php
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden')->values();
    $monthCompare = $overview['month_compare'] ?? [];

    $metricVal = function (?array $col, string $label) {
        if (! is_array($col)) {
            return null;
        }
        foreach ($col['metrics'] ?? [] as $m) {
            if (($m['label'] ?? '') === $label && ($m['kind'] ?? '') === 'ok') {
                return $m['value'];
            }
        }

        return null;
    };

    $super = $cols->firstWhere('key', 'superops');
    $hunt = $cols->firstWhere('key', 'huntress');
    $resolved = $metricVal($super, 'Tickets we closed') ?? $metricVal($super, 'Resolved this month') ?? $metricVal($super, 'Resolved (30d)');
    $sla = $metricVal($super, 'Resolved on time') ?? $metricVal($super, 'SLA met');
    $openTickets = $metricVal($super, 'Open tickets');
    $threats = $metricVal($hunt, 'Threats stopped')
        ?? $metricVal($hunt, 'Threats stopped (MTD)')
        ?? $metricVal($hunt, 'Handled overall')
        ?? $metricVal($hunt, 'Remediated')
        ?? $metricVal($hunt, 'Remediated (snapshot)')
        ?? $metricVal($hunt, 'Resolved incidents');
    $huntLive = is_array($hunt) && ($hunt['state'] ?? '') === 'live';
    $huntSold = is_array($hunt) && ! in_array($hunt['state'] ?? '', ['not_sold', 'hidden'], true);
    // Same rule as glance: no empty “threats stopped” when MDR isn’t sold.
    if ($huntLive && $threats !== null) {
        $statCards = [
            ['value' => $threats, 'label' => 'threats stopped'],
            ['value' => $resolved, 'label' => 'tickets we closed'],
            ['value' => $sla, 'label' => 'resolved on time'],
        ];
    } elseif ($huntSold) {
        $statCards = [
            ['value' => $threats, 'label' => 'threats stopped'],
            ['value' => $resolved, 'label' => 'tickets we closed'],
            ['value' => $sla, 'label' => 'resolved on time'],
        ];
    } else {
        $statCards = [
            ['value' => $resolved, 'label' => 'tickets we closed'],
            ['value' => $openTickets, 'label' => 'open tickets'],
            ['value' => $sla, 'label' => 'resolved on time'],
        ];
    }
    $monthTitle = now()->timezone('Europe/London')->format('F Y');
    $monthReady = ($monthCompare['available'] ?? false) === true;
@endphp

{{--
  1c report - full-width shell (not a narrow card), CSS Grid only.
  No Tailwind layout utilities: production CSS purge broke flex rail/main earlier.
  Brand is already in the portal header - rail starts at “Prepared for”.
--}}
<x-app-layout title="Reports" content-class="max-w-none">
    <style>
        .rp { font-family: Barlow, system-ui, sans-serif; color: #011926; box-sizing: border-box; }
        .rp *, .rp *::before, .rp *::after { box-sizing: border-box; }

        /* Cancel parent main padding so the report uses the full content width */
        .rp {
            margin: -2rem -1rem 0;
            width: auto;
        }
        @media (min-width: 640px) {
            .rp { margin-left: -1.5rem; margin-right: -1.5rem; margin-top: -3rem; }
        }
        @media (min-width: 1024px) {
            .rp { margin-left: -2rem; margin-right: -2rem; }
        }

        .rp-shell {
            display: grid;
            grid-template-columns: 1fr;
            width: 100%;
            max-width: none;
            min-height: 70vh;
            background: #F4F6F8;
            overflow: hidden;
        }
        @media (min-width: 900px) {
            .rp-shell {
                grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
                border-radius: 0;
                box-shadow: 0 4px 24px rgba(0,0,0,.25);
            }
        }

        .rp-rail {
            background: #011926;
            color: #fff;
            padding: 22px 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }
        @media (min-width: 900px) {
            .rp-rail { padding: 32px 28px; gap: 28px; min-height: 70vh; }
        }

        .rp-main {
            background: #F4F6F8;
            color: #011926;
            padding: 22px 16px 32px;
            min-width: 0;
        }
        @media (min-width: 640px) {
            .rp-main { padding: 28px 24px 36px; }
        }
        @media (min-width: 900px) {
            .rp-main { padding: 36px 40px 40px; }
        }
        @media (min-width: 1280px) {
            .rp-main { padding: 40px 48px 48px; }
        }

        .rp-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
        }
        .rp-muted { color: #666; }
        .rp-activity-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .02em;
            color: #071f2e;
            background: #fff4eb;
            border: 1px solid #FF7000;
        }

        .rp-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px 16px;
        }

        .rp-stats {
            display: grid;
            grid-template-columns: 1fr;
            margin-top: 18px;
        }
        @media (min-width: 520px) {
            .rp-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .rp-stats > div { border-right: 1px solid #E5E7EB; }
            .rp-stats > div:last-child { border-right: 0; }
        }
        .rp-stats > div {
            padding: 16px 18px;
            border-bottom: 1px solid #E5E7EB;
        }
        @media (min-width: 520px) {
            .rp-stats > div { border-bottom: 0; }
        }
        .rp-stats-v {
            font-size: clamp(1.75rem, 4vw, 2.125rem);
            font-weight: 700;
            line-height: 1;
        }

        /* Service rows: stack on phone, horizontal from tablet up */
        .rp-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 16px;
            margin-bottom: 10px;
            align-items: start;
        }
        .rp-row-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        @media (min-width: 900px) {
            .rp-row {
                grid-template-columns: minmax(150px, 1.15fr) minmax(0, 2.2fr) auto;
                gap: 16px 20px;
                padding: 18px 22px;
                align-items: center;
            }
            .rp-row-top { display: contents; }
            .rp-row-top > :first-child { grid-column: 1; }
            .rp-row-status { grid-column: 1; margin-top: 6px; }
        }
        @media (min-width: 1100px) {
            .rp-row {
                grid-template-columns: minmax(160px, 1fr) auto minmax(0, 2fr) auto;
            }
            .rp-row-status { grid-column: auto; margin-top: 0; }
        }

        .rp-hi {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px 12px;
            min-width: 0;
        }
        @media (max-width: 379px) {
            .rp-hi { grid-template-columns: 1fr; }
        }

        .rp-note { font-size: 11px; color: #B45309; line-height: 1.4; margin-top: 6px; }
        .rp-link {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            font-size: 13px;
            font-weight: 600;
            color: #FF7000;
            text-decoration: none;
            white-space: nowrap;
        }
        .rp-link:hover { color: #E06500; }
        @media (min-width: 900px) {
            .rp-link { min-height: 0; font-size: 12px; }
        }

        .rp-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            margin-top: 12px;
            padding: 10px 18px;
            background: #FF7000;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            border-radius: 4px;
            text-decoration: none;
            width: 100%;
            text-align: center;
        }
        @media (min-width: 900px) {
            .rp-cta { width: auto; }
        }
        .rp-back {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            margin-top: 8px;
            font-size: 13px;
            color: rgba(255,255,255,.55);
            text-decoration: none;
        }
        @media (min-width: 900px) {
            .rp-back { min-height: 0; margin-top: 14px; font-size: 12px; }
        }

        /* Reports: extra bottom space for mobile bottom nav */
        @media (max-width: 639px) {
            .rp { padding-bottom: 4.5rem; }
        }

        .rp-toggle {
            display: inline-flex;
            border: 1px solid #E5E7EB;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
            flex: none;
        }
        .rp-toggle span {
            padding: 10px 14px;
            font-size: 12px;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
        }
        .rp-period {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            max-width: 18rem;
            flex: 0 1 auto;
        }
        .rp-period-hint {
            margin: 0;
            max-width: 16.5rem;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #FDBA74;
            background: #FFF7ED;
            color: #9A3412;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.45;
            text-align: left;
        }
        .rp-period-locked {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            min-height: 40px;
            font-size: 12px;
            font-weight: 500;
            color: #9CA3AF;
            background: #F3F4F6;
            border-left: 1px solid #E5E7EB;
            user-select: none;
        }
        .rp-period-locked svg { flex: none; opacity: .8; }
        @media (max-width: 640px) {
            .rp-period { align-items: stretch; max-width: none; width: 100%; }
            .rp-period-hint { max-width: none; }
            .rp-toggle { width: 100%; }
            .rp-toggle > span:first-child { flex: 1; justify-content: center; }
            .rp-period-locked { flex: 1; justify-content: center; }
        }
    </style>

    <div class="rp">
        <div class="rp-shell">
            <aside class="rp-rail">
                <div>
                    <div style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.65)">Prepared for</div>
                    <div style="font-size:clamp(1.125rem,3vw,1.25rem);font-weight:700;margin-top:6px;line-height:1.3;word-break:break-word">{{ $client->name }}</div>
                    <div style="font-size:12px;color:rgba(255,255,255,.65);margin-top:4px">Customer service review</div>
                </div>

                <div>
                    <div style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.65);margin-bottom:14px">Your services</div>
                    <div style="display:flex;flex-direction:column;gap:12px">
                        @foreach($cols as $col)
                            @php
                                $check = match ($col['tone'] ?? '') {
                                    'ok' => '#22C55E',
                                    'warn' => '#FACC15',
                                    default => '#64748B',
                                };
                            @endphp
                            <div style="display:flex;align-items:flex-start;gap:10px">
                                <svg width="16" height="16" style="flex:none;margin-top:2px" viewBox="0 0 24 24" fill="none" stroke="{{ $check }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                                <span style="font-size:13px;line-height:1.35">{{ $col['plan_label'] ?? $col['title'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div style="border-top:1px solid #1F2933;padding-top:20px;margin-top:auto">
                    <div style="font-size:12px;color:rgba(255,255,255,.65);line-height:1.6">Questions about your service or this report?</div>
                    <a href="{{ route('support.create') }}" class="rp-cta">Talk to us</a>
                    <a href="{{ route('dashboard') }}" class="rp-back">← Dashboard</a>
                </div>
            </aside>

            <div class="rp-main">
                <div class="rp-head">
                    <div style="min-width:0;flex:1 1 14rem">
                        <div style="font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#FF7000;margin-bottom:10px">Monthly service review</div>
                        <h1 style="margin:0;font-size:clamp(1.45rem,4vw,1.9rem);font-weight:700;letter-spacing:-0.02em;line-height:1.15">{{ $monthTitle }}</h1>
                    </div>
                    @if($monthReady)
                    <div class="rp-period" role="group" aria-label="Report period">
                        <div class="rp-toggle">
                            <span style="font-weight:600;background:#011926;color:#fff">This month</span>
                            <span style="font-weight:500;color:#555">
                                Last month
                                @if(! empty($monthCompare['as_of']))
                                    · {{ \Illuminate\Support\Carbon::parse($monthCompare['as_of'])->format('M j') }}
                                @endif
                            </span>
                        </div>
                    </div>
                    @endif
                </div>

                @if($monthReady && ! empty($monthCompare['value_deltas']))
                    <div class="rp-card" style="margin-top:12px;padding:12px 16px;display:flex;flex-wrap:wrap;gap:10px 18px">
                        @foreach($monthCompare['value_deltas'] as $delta)
                            <div style="font-size:12.5px;color:#64748B">
                                <strong style="color:#011926">{{ $delta['label'] }}</strong>
                                now {{ $delta['current'] ?? '-' }} · then {{ $delta['previous'] ?? '-' }}
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="rp-card rp-stats">
                    @foreach($statCards as $stat)
                        <div>
                            <div class="rp-stats-v" style="{{ ($stat['value'] ?? null) === null ? 'color:rgba(0,0,0,.28)' : '' }}">{{ $stat['value'] ?? 'Checking' }}</div>
                            <div class="rp-muted" style="font-size:12.5px;margin-top:6px">{{ $stat['label'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div style="margin-top:16px">
                    @foreach($cols as $col)
                        @php
                            $tone = $col['tone'] ?? 'neutral';
                            $statusShort = $col['status_label']
                                ?? match ($col['state'] ?? '') {
                                    'live' => 'Healthy',
                                    'not_sold' => 'Not on plan',
                                    'setup_needed' => $col['status_label'] ?? 'Getting ready',
                                    'loading' => 'Checking',
                                    default => '-',
                                };
                            $why = $col['status_reason'] ?? $col['message'] ?? null;
                            $pillBg = match ($tone) {
                                'ok' => 'rgba(34,197,94,.1)',
                                'warn' => 'rgba(250,204,21,.14)',
                                'bad' => 'rgba(239,68,68,.12)',
                                default => 'rgba(100,116,139,.15)',
                            };
                            $pillColor = match ($tone) {
                                'ok' => '#15803D',
                                'warn' => '#B45309',
                                'bad' => '#B91C1C',
                                default => '#475569',
                            };
                            $dot = match ($tone) {
                                'ok' => '#22C55E',
                                'warn' => '#FACC15',
                                'bad' => '#EF4444',
                                default => '#64748B',
                            };
                            $highlights = match ($col['key'] ?? '') {
                                'superops' => [
                                    ['v' => $metricVal($col, 'Open tickets'), 'l' => 'open tickets'],
                                    ['v' => $metricVal($col, 'Waiting on you'), 'l' => 'waiting on you'],
                                    ['v' => $metricVal($col, 'Tickets we closed') ?? $metricVal($col, 'Resolved this month') ?? $metricVal($col, 'Resolved (30d)'), 'l' => 'closed'],
                                    ['v' => $metricVal($col, 'Devices') ?? $metricVal($col, 'Devices managed'), 'l' => 'devices'],
                                ],
                                'm365' => [
                                    ['v' => $metricVal($col, 'Licences assigned'), 'l' => 'licences'],
                                    ['v' => $metricVal($col, 'Secure Score'), 'l' => 'secure score'],
                                    ['v' => $metricVal($col, 'MFA registered'), 'l' => 'MFA'],
                                    ['v' => $metricVal($col, 'Utilisation'), 'l' => 'utilisation'],
                                ],
                                'huntress' => [
                                    ['v' => $metricVal($col, 'Protected devices') ?? $metricVal($col, 'Agent coverage'), 'l' => 'covered'],
                                    ['v' => $metricVal($col, 'Threats stopped') ?? $metricVal($col, 'Threats stopped (MTD)') ?? $metricVal($col, 'Handled overall') ?? $metricVal($col, 'Remediated') ?? $metricVal($col, 'Remediated (snapshot)'), 'l' => 'stopped'],
                                    ['v' => $metricVal($col, 'Open cases') ?? $metricVal($col, 'Open incidents'), 'l' => 'open'],
                                ],
                                'dropsuite' => [
                                    ['v' => $metricVal($col, 'Mailboxes protected'), 'l' => 'mailboxes'],
                                    ['v' => $metricVal($col, 'Succeeded'), 'l' => 'ok 24h'],
                                    ['v' => $metricVal($col, 'Retrying'), 'l' => 'retrying'],
                                ],
                                default => [],
                            };
                        @endphp
                        <div class="rp-card rp-row">
                            <div class="rp-row-top">
                                <div style="min-width:0">
                                    <div style="font-size:14px;font-weight:600">{{ $col['title'] }}</div>
                                    @if($why)
                                        <div class="rp-muted" style="font-size:12px;margin-top:4px;line-height:1.35;max-width:28rem">{{ $why }}</div>
                                    @endif
                                </div>
                                <span class="rp-row-status" title="{{ $why }}" style="display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:4px;font-size:11.5px;font-weight:600;background:{{ $pillBg }};color:{{ $pillColor }};width:fit-content">
                                    <span style="width:7px;height:7px;border-radius:50%;background:{{ $dot }};flex:none"></span>
                                    {{ $statusShort }}
                                </span>
                            </div>
                            <div class="rp-hi">
                                @foreach($highlights as $h)
                                    @php $missing = $h['v'] === null; @endphp
                                    <div style="min-width:0">
                                        <div style="font-size:clamp(15px,3.5vw,17px);font-weight:700;line-height:1.1;{{ $missing ? 'color:rgba(0,0,0,.28)' : '' }}">
                                            {{ $missing ? '-' : $h['v'] }}
                                        </div>
                                        <div class="rp-muted" style="font-size:11px;margin-top:2px">{{ $h['l'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @if(! empty($col['href']) && ($col['state'] ?? '') !== 'not_sold')
                                <a class="rp-link" href="{{ $col['href'] }}">Details →</a>
                            @endif
                        </div>
                        @if(($col['state'] ?? '') !== 'live' && ! empty($col['message']) && empty($why))
                            <p class="rp-note" style="margin:-2px 0 12px 4px">{{ $col['message'] }}</p>
                        @endif
                    @endforeach
                </div>

                @php $activity = $overview['activity'] ?? []; @endphp
                <div class="rp-card" style="margin-top:12px;padding:16px 18px">
                    <div style="font-size:14px;font-weight:600;margin-bottom:10px">What we've done for you</div>
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
                                <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 12px">
                                    <div style="min-width:0">
                                        <div style="font-size:13px;font-weight:600;color:#071f2e">{{ $item['title'] ?? $sourceLabel }}</div>
                                        @if(filled($item['text'] ?? null))
                                            <div class="rp-muted" style="margin-top:2px;font-size:12.5px;line-height:1.4">{{ $item['text'] }}</div>
                                        @endif
                                        @if(filled($item['badge'] ?? null))
                                            <span class="rp-activity-badge">{{ $item['badge'] }}</span>
                                        @endif
                                    </div>
                                    <div class="rp-muted" style="text-align:right;font-size:11px;white-space:nowrap">
                                        @if($at){{ $at }}@endif
                                        @if(filled($item['ref'] ?? null))
                                            <div style="margin-top:2px;color:#071f2e;font-weight:600">{{ $item['ref'] }}</div>
                                        @endif
                                        <div style="margin-top:2px;text-transform:uppercase;letter-spacing:.04em;font-weight:500">{{ $sourceLabel }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="rp-muted" style="margin:0;font-size:13px;line-height:1.5">
                            {{ $activity['message'] ?? 'No recent support or security activity in the latest snapshots.' }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
