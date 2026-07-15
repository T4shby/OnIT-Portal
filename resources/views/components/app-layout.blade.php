@props(['title' => null, 'contentClass' => 'max-w-portal'])

<x-layouts.app :title="$title" :content-class="$contentClass">
    {{ $slot }}
</x-layouts.app>
