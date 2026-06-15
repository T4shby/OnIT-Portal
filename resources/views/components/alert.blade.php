@props(['type' => 'info'])

@php
$classes = match($type) {
    'success' => 'bg-green-50 border-green-200 text-green-800',
    'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
    'danger' => 'bg-red-50 border-red-200 text-red-800',
    default => 'bg-blue-50 border-blue-200 text-blue-800',
};
@endphp

<div {{ $attributes->merge(['class' => "rounded-lg border p-4 {$classes}"]) }} x-data="{ show: true }" x-show="show">
    <div class="flex justify-between items-start">
        <p class="text-sm">{{ $slot }}</p>
        <button @click="show = false" class="ml-4 text-current opacity-50 hover:opacity-100">&times;</button>
    </div>
</div>
