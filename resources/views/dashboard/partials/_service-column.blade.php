@php
    $state = $col['state'] ?? 'neutral';
    $tone = $col['tone'] ?? 'neutral';
    $border = match ($tone) {
        'ok' => 'border-emerald-400/40',
        'warn' => 'border-amber-400/40',
        'muted' => 'border-white/10',
        default => 'border-onit-border',
    };
    $statusColor = match ($state) {
        'live' => $tone === 'warn' ? 'text-amber-300' : 'text-emerald-400',
        'not_sold' => 'text-white/40',
        'loading' => 'text-sky-300',
        default => 'text-amber-300',
    };
@endphp
<article class="flex h-full flex-col border {{ $border }} bg-onit-surface">
    <header class="border-b border-white/10 px-4 py-4">
        <p class="font-condensed text-[0.65rem] font-bold uppercase tracking-wider text-onit">{{ $col['source'] ?? '' }}</p>
        <h3 class="mt-1 font-condensed text-lg font-bold uppercase tracking-wide text-white">{{ $col['title'] ?? '' }}</h3>
        <p class="mt-1 text-xs text-white/50">{{ $col['plan_label'] ?? '' }}</p>
        <p class="mt-3 font-condensed text-xs font-bold uppercase tracking-wide {{ $statusColor }}">
            {{ $col['status_label'] ?? '—' }}
        </p>
    </header>

    <div class="flex flex-1 flex-col px-4 py-4">
        @if(($col['message'] ?? null) && ! in_array($state, ['live'], true))
            <p class="mb-4 text-sm leading-relaxed text-white/70">{{ $col['message'] }}</p>
            @if(in_array($state, ['setup_needed', 'platform', 'not_sold', 'cold'], true))
                <p class="mb-4 text-xs leading-relaxed text-amber-200/90 border border-amber-400/20 bg-amber-400/5 px-3 py-2">
                    @if($state === 'not_sold')
                        Not included in this organisation’s products.
                    @elseif($state === 'setup_needed')
                        Sold — mapping / tenant setup still needed. Account manager / On IT technicians must finish this.
                    @elseif($state === 'platform')
                        Platform credentials disabled or incomplete on the On IT side.
                    @elseif($state === 'cold')
                        Product is linked but no successful data pull yet — auto-refresh should fill this; if it stays empty, staff check Integration Health.
                    @endif
                </p>
            @endif
        @endif

        @if(($col['metrics'] ?? []) !== [])
            <ul class="space-y-3">
                @foreach($col['metrics'] as $metric)
                    @php $kind = $metric['kind'] ?? 'ok'; @endphp
                    <li class="flex items-start justify-between gap-3 border-b border-white/5 pb-2 last:border-0">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wide text-white/45 font-condensed">{{ $metric['label'] }}</p>
                            @if(! empty($metric['hint']))
                                <p class="mt-0.5 text-[0.7rem] leading-snug {{ $kind === 'pipeline' ? 'text-amber-200/80' : 'text-white/35' }}">
                                    {{ $metric['hint'] }}
                                </p>
                            @endif
                        </div>
                        <p @class([
                            'shrink-0 font-condensed text-sm font-bold uppercase tracking-wide',
                            'text-amber-300' => $kind === 'pipeline',
                            'text-white/40' => $kind === 'empty',
                            'text-white' => $kind === 'ok',
                        ])>
                            {{ $metric['value'] }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif

        @if(! empty($col['as_of']))
            <p class="mt-4 text-[0.65rem] text-white/35">
                As of {{ $col['as_of']->timezone('Europe/London')->format('d M Y H:i') }} UK
            </p>
        @endif
    </div>

    @if(! empty($col['href']) && ! in_array($state, ['not_sold', 'hidden'], true))
        <footer class="mt-auto border-t border-white/10 px-4 py-3">
            <a href="{{ $col['href'] }}" class="text-sm text-onit hover:text-white font-condensed font-semibold uppercase tracking-wide">
                {{ $col['href_label'] ?? 'Open →' }}
            </a>
        </footer>
    @endif
</article>
