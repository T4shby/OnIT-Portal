@props(['size' => 'md'])

@php
$box = match($size) {
    'lg' => 'h-14 w-14 text-lg',
    'sm' => 'h-8 w-8 text-xs',
    default => 'h-10 w-10 text-sm',
};
@endphp

<div {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-xl bg-onit font-bold text-white {$box}"]) }}>
    <span>IT</span>
</div>
