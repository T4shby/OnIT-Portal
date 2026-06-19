@props(['link'])

@php
$logo = match (true) {
    in_array($link->link_type?->value, ['superops_sso', 'superops_embedded'], true) => 'superops',
    ($link->link_type?->value ?? '') === 'pax8_sso' => 'pax8',
    str_contains(strtolower($link->name ?? ''), 'superops') => 'superops',
    str_contains(strtolower($link->name ?? ''), 'pax8') => 'pax8',
    ($link->icon ?? '') === 'lifebuoy' => 'superops',
    ($link->icon ?? '') === 'key' => 'pax8',
    default => null,
};
@endphp

@if($logo)
    <img
        src="{{ asset("images/logos/{$logo}.svg") }}"
        alt="{{ $link->name }}"
        class="h-7 w-auto sm:h-8"
        width="140"
        height="28"
        loading="lazy"
    />
@else
    <span class="text-lg font-semibold text-onit-ink">{{ $link->name }}</span>
@endif
