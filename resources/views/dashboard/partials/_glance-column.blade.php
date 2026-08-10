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
@endphp
<div class="glance-card flex flex-col gap-4 p-6">
    <div class="flex items-start justify-between gap-2.5">
        <div class="flex items-center gap-2.5 min-w-0">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FF7000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
            <div class="min-w-0">
                <div class="text-sm font-semibold leading-tight">{{ $col['title'] }}</div>
                <div class="mt-0.5 text-[10.5px] glance-muted">{{ $col['source'] }}</div>
            </div>
        </div>
        <span class="glance-dot mt-1" style="background:{{ $dot }}" title="{{ $col['status_label'] ?? '' }}"></span>
    </div>

    @if(($col['message'] ?? null) && $state !== 'live')
        <div class="rounded border border-amber-400/25 bg-amber-400/5 px-3 py-2.5">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-200">
                @if($state === 'not_sold') Not sold
                @elseif($state === 'setup_needed') Setup needed
                @elseif($state === 'platform') Platform off
                @elseif($state === 'cold') Never loaded
                @elseif($state === 'loading') Loading
                @elseif($state === 'error') Error
                @else Attention
                @endif
            </p>
            <p class="mt-1 text-[12px] leading-snug glance-muted">{{ $col['message'] }}</p>
        </div>
    @endif

    @if(($col['metrics'] ?? []) !== [])
        <div class="flex flex-col gap-2.5">
            @foreach($col['metrics'] as $i => $metric)
                @php
                    $kind = $metric['kind'] ?? 'ok';
                    // Visual group separators similar to mockup (~ after tickets / after license blocks)
                    $sepBefore = in_array($metric['label'] ?? '', [
                        'Devices managed',
                        'Secure Score',
                        'MFA coverage',
                        'Incidents this month',
                        'Identity (ITDR) alerts',
                        'Last backup run',
                        'Restore points kept',
                    ], true);
                @endphp
                @if($sepBefore)
                    <div class="glance-divider my-0.5"></div>
                @endif
                <div class="glance-metric">
                    <span class="glance-metric-label">{{ $metric['label'] }}</span>
                    <span @class([
                        'glance-metric-value',
                        'text-amber-300' => $kind === 'pipeline',
                        'text-white/40' => $kind === 'empty',
                        'text-[#FACC15]' => $kind === 'ok' && str_contains(strtolower($metric['value'] ?? ''), 'issue'),
                    ])>
                        {{ $metric['value'] }}
                        @if(! empty($metric['suffix']))
                            <span class="font-normal glance-muted">{{ $metric['suffix'] }}</span>
                        @endif
                    </span>
                </div>
                @if($kind === 'pipeline' && ! empty($metric['hint']))
                    <p class="text-[10.5px] leading-snug text-amber-200/80 -mt-1">{{ $metric['hint'] }}</p>
                @elseif($kind === 'empty' && ! empty($metric['hint']))
                    <p class="text-[10.5px] leading-snug text-white/35 -mt-1">{{ $metric['hint'] }}</p>
                @endif
            @endforeach
        </div>
    @endif

    @if(! empty($col['as_of']))
        <p class="text-[10px] text-white/35">As of {{ $col['as_of']->timezone('Europe/London')->format('d M Y H:i') }} UK</p>
    @endif

    @if(! empty($col['href']) && $state !== 'not_sold' && $state !== 'hidden')
        <a href="{{ $col['href'] }}" class="glance-link mt-auto">{{ $col['href_label'] ?? 'Details →' }}</a>
    @endif
</div>
