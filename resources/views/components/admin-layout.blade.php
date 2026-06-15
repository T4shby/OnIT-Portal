@props(['title' => null])

<x-layouts.admin :title="$title">
    {{ $slot }}
</x-layouts.admin>
