@props(['size' => 'md'])

@php
$box = match($size) {
    'lg' => 'h-16 w-16 rounded-2xl text-xl',
    'sm' => 'h-8 w-8 rounded-lg text-sm',
    default => 'h-10 w-10 rounded-xl text-base',
};
@endphp

<div {{ $attributes->merge(['class' => "inline-flex items-center justify-center bg-gradient-to-br from-onit to-onit-hover font-bold text-white shadow-lg shadow-onit/30 {$box}"]) }}>
  <span>IT</span>
</div>
