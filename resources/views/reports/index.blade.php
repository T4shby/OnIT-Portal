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
    $resolved = $metricVal($super, 'Resolved this month') ?? $metricVal($super, 'Resolved (30d)');
    $sla = $metricVal($super, 'SLA met');
    // Prefer real remediations for the headline number; do not invent MTD threats.
    $threats = $metricVal($hunt, 'Remediated (snapshot)')
        ?? $metricVal($hunt, 'Resolved incidents');
    $threatsLabel = $threats !== null ? 'remediated (snapshot)' : 'threats stopped';
    $monthTitle = now()->timezone('Europe/London')->format('F Y');
@endphp

{{--
  1c layout — CSS Grid with all sizing inline where it matters.
  Do NOT use Tailwind flex utilities here: purged prod CSS left flex-row + w-full
  rail, which shoved the light main panel off the right edge of the viewport.
--}}
<x-app-layout title="Reports" content-class="max-w-none">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .rp { font-family: 'Poppins', system-ui, sans-serif; color: #011926; box-sizing: border-box; }
        .rp *, .rp *::before, .rp *::after { box-sizing: border-box; }
        .rp-shell {
            display: grid;
            grid-template-columns: 1fr;
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            min-height: 70vh;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,.25);
            background: #F4F6F8;
        }
        @media (min-width: 900px) {
            .rp-shell {
                grid-template-columns: 280px minmax(0, 1fr);
            }
        }
        .rp-rail {
            background: #011926;
            color: #fff;
            padding: 28px 24px;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }
        .rp-main {
            background: #F4F6F8;
            color: #011926;
            padding: 28px 22px 36px;
            min-width: 0;
        }
        @media (min-width: 900px) {
            .rp-main { padding: 36px 40px 40px; }
        }
        .rp-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
        }
        .rp-muted { color: #666; }
        .rp-stats {
            display: grid;
            grid-template-columns: 1fr;
            margin-top: 20px;
        }
        @media (min-width: 640px) {
            .rp-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .rp-stats > div { border-right: 1px solid #E5E7EB; }
            .rp-stats > div:last-child { border-right: 0; }
        }
        .rp-stats > div { padding: 18px 20px; }
        .rp-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            padding: 18px 20px;
            margin-bottom: 10px;
            align-items: center;
        }
        @media (min-width: 800px) {
            .rp-row {
                grid-template-columns: minmax(140px, 1.1fr) auto minmax(0, 2fr) auto;
                gap: 16px 20px;
            }
        }
        .rp-hi {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .rp-note { font-size: 11px; color: #B45309; line-height: 1.4; margin-top: 6px; }
        .rp-link { font-size: 12px; font-weight: 600; color: #FF7000; text-decoration: none; white-space: nowrap; }
        .rp-link:hover { color: #E06500; }
    </style>

    <div class="rp" style="margin-top:-1rem">
        <div class="rp-shell">
            <aside class="rp-rail">
                <div style="display:flex;align-items:center;gap:12px">
                    <x-portal-logo size="md" />
                    <span style="font-size:14px;font-weight:600">On IT</span>
                </div>

                <div>
                    <div style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.65)">Prepared for</div>
                    <div style="font-size:18px;font-weight:700;margin-top:6px;line-height:1.3;word-break:break-word">{{ $client->name }}</div>
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
                    <a href="{{ route('support.create') }}" style="display:inline-block;margin-top:12px;padding:10px 18px;background:#FF7000;color:#fff;font-size:13px;font-weight:600;border-radius:4px;text-decoration:none">Talk to an Expert</a>
                    <a href="{{ route('dashboard') }}" style="display:block;margin-top:14px;font-size:12px;color:rgba(255,255,255,.55);text-decoration:none">← Dashboard</a>
                </div>
            </aside>

            <div class="rp-main">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:16px">
                    <div style="min-width:0;flex:1 1 220px">
                        <div style="font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#FF7000;margin-bottom:10px">Monthly service review</div>
                        <h1 style="margin:0;font-size:clamp(1.5rem,3vw,1.85rem);font-weight:700;letter-spacing:-0.02em;line-height:1.15">{{ $monthTitle }}</h1>
                    </div>
                    <div style="display:inline-flex;border:1px solid #E5E7EB;border-radius:4px;overflow:hidden;background:#fff" title="{{ $monthCompare['message'] ?? 'History not set up' }}">
                        <span style="padding:8px 14px;font-size:12px;font-weight:600;background:#011926;color:#fff">This month</span>
                        <span style="padding:8px 14px;font-size:12px;font-weight:500;color:#999;cursor:not-allowed">Last month</span>
                    </div>
                </div>

                <div class="rp-card rp-stats">
                    <div>
                        <div style="font-size:34px;font-weight:700;line-height:1;{{ $threats === null ? 'color:#B45309;font-size:1.35rem' : '' }}">
                            {{ $threats ?? '—' }}
                        </div>
                        <div class="rp-muted" style="font-size:12.5px;margin-top:6px">{{ $threatsLabel }}</div>
                    </div>
                    <div>
                        <div style="font-size:34px;font-weight:700;line-height:1;{{ $resolved === null ? 'color:#B45309;font-size:1.35rem' : '' }}">
                            {{ $resolved ?? '—' }}
                        </div>
                        <div class="rp-muted" style="font-size:12.5px;margin-top:6px">tickets resolved</div>
                    </div>
                    <div>
                        <div style="font-size:34px;font-weight:700;line-height:1;{{ $sla === null ? 'color:#B45309;font-size:1.35rem' : '' }}">
                            {{ $sla ?? '—' }}
                        </div>
                        <div class="rp-muted" style="font-size:12.5px;margin-top:6px">SLA met</div>
                    </div>
                </div>

                <div style="margin-top:18px">
                    @foreach($cols as $col)
                        @php
                            $tone = $col['tone'] ?? 'neutral';
                            $statusShort = match ($col['state'] ?? '') {
                                'live' => $col['status_label'] ?? 'Live',
                                'not_sold' => 'Not sold',
                                'setup_needed' => 'Setup needed',
                                'platform' => 'Platform off',
                                'cold' => 'Never loaded',
                                'loading' => 'Loading',
                                'error' => 'Error',
                                default => $col['status_label'] ?? '—',
                            };
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
                                    ['v' => $metricVal($col, 'Resolved this month') ?? $metricVal($col, 'Resolved (30d)'), 'l' => 'resolved'],
                                    ['v' => $metricVal($col, 'Devices managed'), 'l' => 'devices'],
                                ],
                                'm365' => [
                                    ['v' => $metricVal($col, 'Licences assigned'), 'l' => 'licences assigned'],
                                    ['v' => $metricVal($col, 'Utilisation'), 'l' => 'utilisation'],
                                    ['v' => $metricVal($col, 'Licensed users'), 'l' => 'licensed users'],
                                ],
                                'huntress' => [
                                    ['v' => $metricVal($col, 'Agent coverage'), 'l' => 'devices covered'],
                                    ['v' => $metricVal($col, 'Remediated (snapshot)'), 'l' => 'remediated'],
                                    ['v' => $metricVal($col, 'Open incidents'), 'l' => 'open'],
                                ],
                                'dropsuite' => [
                                    ['v' => $metricVal($col, 'Mailboxes protected'), 'l' => 'mailboxes'],
                                    ['v' => $metricVal($col, 'Succeeded'), 'l' => 'succeeded 24h'],
                                    ['v' => $metricVal($col, 'Retrying'), 'l' => 'retrying'],
                                ],
                                default => [],
                            };
                        @endphp
                        <div class="rp-card rp-row">
                            <div style="min-width:0">
                                <div style="font-size:13.5px;font-weight:600">{{ $col['title'] }}</div>
                                <div class="rp-muted" style="font-size:11px;margin-top:2px">{{ $col['source'] }}</div>
                            </div>
                            <span style="display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:4px;font-size:11.5px;font-weight:600;background:{{ $pillBg }};color:{{ $pillColor }};width:fit-content">
                                <span style="width:7px;height:7px;border-radius:50%;background:{{ $dot }};flex:none"></span>
                                {{ $statusShort }}
                            </span>
                            <div class="rp-hi">
                                @foreach($highlights as $h)
                                    @php $missing = $h['v'] === null; @endphp
                                    <div style="min-width:0">
                                        <div style="font-size:17px;font-weight:700;line-height:1.1;{{ $missing ? 'color:#B45309' : '' }}">
                                            {{ $missing ? '—' : $h['v'] }}
                                        </div>
                                        <div class="rp-muted" style="font-size:11.5px;margin-top:2px">{{ $h['l'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @if(! empty($col['href']) && ($col['state'] ?? '') !== 'not_sold')
                                <a class="rp-link" href="{{ $col['href'] }}">Details →</a>
                            @endif
                        </div>
                        @if(($col['state'] ?? '') !== 'live' && ! empty($col['message']))
                            <p class="rp-note" style="margin:-2px 0 12px 4px">{{ $col['message'] }}</p>
                        @endif
                    @endforeach
                </div>

                <div class="rp-card" style="margin-top:12px;padding:18px 20px">
                    <div style="font-size:14px;font-weight:600;margin-bottom:6px">What we've done for you</div>
                    <p class="rp-muted" style="margin:0;font-size:13px;line-height:1.5">Activity history not available yet.</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
