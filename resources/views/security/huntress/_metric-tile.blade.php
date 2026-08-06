@php
    $valueClass = match ($tone ?? 'neutral') {
        'attention' => 'text-amber-300',
        'good' => 'text-emerald-400',
        'muted' => 'text-white/50',
        default => 'text-white',
    };
    $display = ! isset($value) || $value === null
        ? '—'
        : (is_numeric($value) ? number_format((int) $value) : $value);
@endphp
<div class="border border-white/10 bg-[#011926]/50 p-4 sm:p-5">
    <div class="flex items-start gap-3">
        @if(! empty($icon))
            <div class="shrink-0 mt-0.5 text-onit/90" aria-hidden="true">
                {!! $icon !!}
            </div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="portal-label mb-1">{{ $label }}</p>
            <p class="text-3xl sm:text-4xl font-condensed font-bold tabular-nums {{ $valueClass }}">{{ $display }}</p>
            @if(filled($hint ?? null))
                <p class="portal-body-muted text-xs mt-2 leading-relaxed">{{ $hint }}</p>
            @endif
        </div>
    </div>
</div>
