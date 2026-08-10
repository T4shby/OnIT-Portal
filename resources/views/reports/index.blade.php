@php
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden')->values();
    $activity = $overview['activity'] ?? [];
    $monthCompare = $overview['month_compare'] ?? [];
    $period = $overview['period_label'] ?? now()->timezone('Europe/London')->format('F Y');

    $super = $cols->firstWhere('key', 'superops');
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

    $reportRows = [];
    foreach ($cols as $col) {
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
        $tone = $col['tone'] ?? 'neutral';
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

        // Three highlight numbers per row (mockup-style); prefer real; else "—"
        $highlights = match ($col['key'] ?? '') {
            'superops' => [
                ['v' => $metricVal($col, 'Open tickets'), 'l' => 'open tickets'],
                ['v' => null, 'l' => 'avg response', 'pipeline' => true],
                ['v' => $metricVal($col, 'Devices managed'), 'l' => 'devices'],
            ],
            'm365' => [
                ['v' => $metricVal($col, 'Licences assigned'), 'l' => 'licences assigned'],
                ['v' => null, 'l' => 'Secure Score', 'pipeline' => true],
                ['v' => null, 'l' => 'users on MFA', 'pipeline' => true],
            ],
            'huntress' => [
                ['v' => $metricVal($col, 'Agent coverage'), 'l' => 'devices covered'],
                ['v' => $metricVal($col, 'Remediated (snapshot)') ?? $metricVal($col, 'Resolved incidents'), 'l' => 'incidents remediated'],
                ['v' => $metricVal($col, 'Open incidents'), 'l' => 'open incidents'],
            ],
            'dropsuite' => [
                ['v' => $metricVal($col, 'Mailboxes protected'), 'l' => 'mailboxes protected'],
                ['v' => $metricVal($col, 'Succeeded'), 'l' => 'succeeded (24h)'],
                ['v' => null, 'l' => 'restore points kept', 'pipeline' => true],
            ],
            default => [],
        };

        $reportRows[] = [
            'col' => $col,
            'status_short' => $statusShort,
            'pill_bg' => $pillBg,
            'pill_color' => $pillColor,
            'dot' => $dot,
            'highlights' => $highlights,
        ];
    }

    $resolved = $metricVal($super, 'Resolved this month') ?? $metricVal($super, 'Resolved (30d)');
    $sla = $metricVal($super, 'SLA met');
    $threats = $metricVal($cols->firstWhere('key', 'huntress'), 'Remediated (snapshot)')
        ?? $metricVal($cols->firstWhere('key', 'huntress'), 'Resolved incidents');
@endphp

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    .report-ui { font-family: 'Poppins', system-ui, sans-serif; }
    .report-rail { background: #011926; color: #fff; }
    .report-main { background: #F4F6F8; color: #011926; }
    .report-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04); }
    .report-muted { color: #666666; }
    .report-orange { color: #FF7000; font-weight: 600; font-size: 12px; }
    .report-orange:hover { color: #E06500; }
</style>

{{-- Full-bleed 1c: break out of portal max-width padding via negative margin --}}
<x-app-layout title="Reports" content-class="max-w-none !px-0 !py-0">
<div class="report-ui flex min-h-[70vh] flex-col lg:flex-row -mt-8 sm:-mt-12">

    {{-- Left rail --}}
    <aside class="report-rail flex w-full flex-col gap-7 px-7 py-8 lg:w-[300px] lg:flex-none lg:px-7 lg:py-8">
        <div class="flex items-center gap-3">
            <x-portal-logo size="md" />
            <span class="text-sm font-semibold tracking-wide">On IT</span>
        </div>
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-widest text-white/65">Prepared for</div>
            <div class="mt-1.5 text-lg font-bold leading-snug">{{ $client->name }}</div>
            <div class="mt-1 text-xs text-white/65">Customer service review</div>
        </div>
        <div>
            <div class="mb-3.5 text-[11px] font-semibold uppercase tracking-widest text-white/65">Your services</div>
            <div class="flex flex-col gap-3">
                @foreach($cols as $col)
                    @php
                        $check = match ($col['tone'] ?? '') {
                            'ok' => '#22C55E',
                            'warn' => '#FACC15',
                            default => '#64748B',
                        };
                    @endphp
                    <div class="flex items-center gap-2.5">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="{{ $check }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                        <span class="text-[13px]">{{ $col['plan_label'] ?? $col['title'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="mt-auto border-t border-[#1F2933] pt-5">
            <div class="text-xs leading-relaxed text-white/65">Questions about your service or this report?</div>
            <a href="{{ route('support.create') }}" class="mt-3 inline-block rounded bg-onit px-[18px] py-2.5 text-[13px] font-semibold text-white hover:bg-onit-hover">Talk to an Expert</a>
            <a href="{{ route('dashboard') }}" class="mt-3 block text-xs text-white/55 hover:text-onit">← Back to dashboard</a>
        </div>
    </aside>

    {{-- Main --}}
    <div class="report-main min-w-0 flex-1 px-5 py-8 sm:px-8 lg:px-10">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-onit">Monthly service review</div>
                <h1 class="m-0 text-[1.75rem] font-bold tracking-tight">{{ now()->timezone('Europe/London')->format('F Y') }}</h1>
                <p class="mt-2 max-w-xl text-[12.5px] leading-relaxed report-muted">
                    Live figures from sold products only. Items marked <span class="font-semibold text-amber-700">Not set up</span> need a future feed or Microsoft permission — numbers are never invented.
                </p>
            </div>
            <div class="inline-flex shrink-0 overflow-hidden rounded border border-[#E5E7EB] bg-white" title="{{ $monthCompare['message'] ?? '' }}">
                <span class="bg-[#011926] px-3.5 py-1.5 text-xs font-semibold text-white">This month</span>
                <span class="cursor-not-allowed px-3.5 py-1.5 text-xs font-medium report-muted">Last month</span>
            </div>
        </div>

        {{-- Headline stats --}}
        <div class="report-card mt-6 grid grid-cols-1 divide-y divide-[#E5E7EB] sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <div class="px-7 py-5">
                <div class="text-4xl font-bold leading-none">{{ $threats ?? '—' }}</div>
                <div class="mt-1.5 text-[12.5px] report-muted">
                    threats stopped
                    @if($threats === null)
                        <span class="block text-[11px] font-medium text-amber-700">Not set up (uses Huntress MTD later)</span>
                    @else
                        <span class="block text-[11px] text-amber-700">Snapshot stand-in · MoM not set up</span>
                    @endif
                </div>
            </div>
            <div class="px-7 py-5">
                <div class="text-4xl font-bold leading-none">{{ $resolved ?? '—' }}</div>
                <div class="mt-1.5 text-[12.5px] report-muted">
                    tickets resolved
                    @if($resolved === null)
                        <span class="block text-[11px] font-medium text-amber-700">Not available yet</span>
                    @else
                        <span class="block text-[11px] text-amber-700">30d total · avg response not set up</span>
                    @endif
                </div>
            </div>
            <div class="px-7 py-5">
                <div class="text-4xl font-bold leading-none">{{ $sla ?? '—' }}</div>
                <div class="mt-1.5 text-[12.5px] report-muted">
                    SLA met
                    @if($sla === null)
                        <span class="block text-[11px] font-medium text-amber-700">Not available yet</span>
                    @else
                        <span class="block text-[11px] text-amber-700">vs last month not set up</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Stacked source rows --}}
        <div class="mt-5 flex flex-col gap-3">
            @foreach($reportRows as $row)
                @php $c = $row['col']; @endphp
                <div class="report-card flex flex-col gap-4 px-5 py-5 lg:flex-row lg:items-center lg:gap-6 lg:px-7">
                    <div class="flex w-full items-center gap-3 lg:w-[250px] lg:flex-none">
                        <div class="min-w-0">
                            <div class="text-[13.5px] font-semibold">{{ $c['title'] }}</div>
                            <div class="text-[11px] report-muted">{{ $c['source'] }}</div>
                        </div>
                    </div>
                    <span class="inline-flex w-fit items-center justify-center gap-1.5 rounded px-3 py-1 text-[11.5px] font-semibold lg:w-[150px] lg:flex-none"
                          style="background:{{ $row['pill_bg'] }};color:{{ $row['pill_color'] }}">
                        <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:{{ $row['dot'] }}"></span>
                        {{ $row['status_short'] }}
                    </span>
                    <div class="flex flex-1 flex-wrap gap-8 text-[12.5px]">
                        @foreach($row['highlights'] as $h)
                            <div>
                                <div class="text-[17px] font-bold {{ !empty($h['pipeline']) || $h['v'] === null ? 'text-amber-700' : '' }}">
                                    {{ !empty($h['pipeline']) || $h['v'] === null ? '—' : $h['v'] }}
                                </div>
                                <div class="text-[11.5px] report-muted">
                                    {{ $h['l'] }}
                                    @if(!empty($h['pipeline']) || $h['v'] === null)
                                        <span class="text-amber-700"> · not set up</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if(! empty($c['href']) && ($c['state'] ?? '') !== 'not_sold')
                        <a href="{{ $c['href'] }}" class="report-orange shrink-0">Details →</a>
                    @endif
                </div>
                @if(($c['state'] ?? '') !== 'live' && ! empty($c['message']))
                    <p class="-mt-1 mb-1 px-2 text-[11.5px] text-amber-800">{{ $c['message'] }}</p>
                @endif
            @endforeach
        </div>

        {{-- Activity --}}
        <div class="report-card mt-5 px-7 py-5">
            <div class="mb-3.5 flex flex-wrap items-baseline justify-between gap-2">
                <div class="text-sm font-semibold">What we've done for you</div>
                <span class="text-xs font-semibold text-amber-700">Full history not set up</span>
            </div>
            <div class="rounded border border-amber-300/50 bg-amber-50 px-4 py-4">
                <p class="text-[12.5px] leading-relaxed text-amber-950/80">
                    {{ $activity['message'] ?? 'Activity timeline requires a cross-product event pipeline.' }}
                </p>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
