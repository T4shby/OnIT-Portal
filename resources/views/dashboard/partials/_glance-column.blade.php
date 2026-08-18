@php
    $state = $col['state'] ?? '';
    $tone = $col['tone'] ?? 'neutral';
    $dot = match ($tone) {
        'ok' => '#22C55E',
        'warn' => '#FACC15',
        'bad' => '#EF4444',
        'muted' => '#64748B',
        default => '#FACC15',
    };
    $icon = match ($col['key'] ?? '') {
        'superops' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
        'm365' => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>',
        'huntress' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        'dropsuite' => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path>',
        default => '<circle cx="12" cy="12" r="9"></circle>',
    };
    // Live / empty only - pipeline metrics stripped at the service layer, belt-and-braces here.
    $metrics = collect($col['metrics'] ?? [])
        ->reject(fn ($m) => ($m['kind'] ?? '') === 'pipeline')
        ->values();
@endphp
<div class="glance-card" style="display:flex;flex-direction:column;gap:1rem;padding:1.35rem 1.4rem;height:100%">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
        <div style="display:flex;align-items:center;gap:10px;min-width:0">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FF7000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
            <div style="min-width:0">
                <div style="font-size:14px;font-weight:600;line-height:1.25">{{ $col['title'] }}</div>
            </div>
        </div>
        <span class="glance-dot" style="background:{{ $dot }};margin-top:6px" title="{{ $col['status_label'] ?? '' }}"></span>
    </div>

    @if(! empty($col['status_reason']) || (! empty($col['message']) && $state !== 'live'))
        @php
            $why = $col['status_reason'] ?? $col['message'];
            $whyBorder = match ($tone) {
                'ok' => 'rgba(34,197,94,.3)',
                'bad' => 'rgba(239,68,68,.35)',
                'warn' => 'rgba(250,204,21,.3)',
                default => 'rgba(100,116,139,.3)',
            };
            $whyBg = match ($tone) {
                'ok' => 'rgba(34,197,94,.08)',
                'bad' => 'rgba(239,68,68,.1)',
                'warn' => 'rgba(250,204,21,.08)',
                default => 'rgba(100,116,139,.1)',
            };
            $whyLabel = match ($tone) {
                'ok' => '#86EFAC',
                'bad' => '#FCA5A5',
                'warn' => '#FDE68A',
                default => 'rgba(255,255,255,.7)',
            };
        @endphp
        <div style="border-radius:4px;border:1px solid {{ $whyBorder }};background:{{ $whyBg }};padding:8px 10px">
            <p style="margin:0;font-size:11px;font-weight:600;letter-spacing:.04em;color:{{ $whyLabel }}">
                {{ $col['status_label'] ?? 'Status' }}
            </p>
            <p style="margin:4px 0 0;font-size:12px;line-height:1.4" class="glance-muted">{{ $why }}</p>
        </div>
    @endif

    @if($metrics->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:10px">
            @foreach($metrics as $metric)
                @php
                    $kind = $metric['kind'] ?? 'ok';
                    $sepBefore = in_array($metric['label'] ?? '', [
                        'Devices managed',
                        'Utilisation',
                        'Open incidents',
                        'Last backup run',
                    ], true);
                @endphp
                @if($sepBefore)
                    <div class="glance-divider"></div>
                @endif
                <div class="glance-metric">
                    <span class="glance-metric-label">{{ $metric['label'] }}</span>
                    <span class="glance-metric-value" style="{{ $kind === 'empty' ? 'color:rgba(255,255,255,.35)' : 'color:#fff' }}">
                        @if($kind === 'empty')
                            -
                        @else
                            {{ $metric['value'] }}
                            @if(! empty($metric['suffix']))
                                <span style="font-weight:400" class="glance-muted">{{ $metric['suffix'] }}</span>
                            @endif
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if(! empty($col['as_of']))
        <p style="margin:0;font-size:10px;color:rgba(255,255,255,.35)">Updated {{ $col['as_of']->timezone('Europe/London')->format('d M Y H:i') }} UK</p>
    @endif

    @if(! empty($col['href']) && $state !== 'not_sold' && $state !== 'hidden')
        <a href="{{ $col['href'] }}" class="glance-link" style="margin-top:auto">{{ $col['href_label'] ?? 'Details →' }}</a>
    @endif
</div>
