@props(['type' => 'info'])

@php
$classes = match($type) {
    'success' => 'border-green-500/40 bg-green-500/10 text-green-300',
    'warning' => 'border-amber-500/40 bg-amber-500/10 text-amber-200',
    'danger' => 'border-red-500/40 bg-red-500/10 text-red-300',
    default => 'border-onit/40 bg-onit/10 text-white/80',
};
@endphp

<div {{ $attributes->merge(['class' => "border p-4 {$classes}"]) }} x-data="{ show: true }" x-show="show">
    <div class="flex items-start justify-between gap-4">
        <p class="text-sm font-light">{{ $slot }}</p>
        <button type="button" @click="show = false" class="text-current opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
    </div>
</div>
