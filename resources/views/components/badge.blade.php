@props(['variant' => 'default'])

@php
$classes = match($variant) {
    'success' => 'border border-green-500/30 bg-green-500/15 text-green-300',
    'warning' => 'border border-amber-500/30 bg-amber-500/15 text-amber-200',
    'danger' => 'border border-red-500/30 bg-red-500/15 text-red-300',
    'info' => 'border border-onit/30 bg-onit/15 text-onit',
    default => 'border border-white/10 bg-white/5 text-white/70',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2 py-0.5 font-condensed text-[0.7rem] font-bold uppercase tracking-wide {$classes}"]) }}>
    {{ $slot }}
</span>
