@props(['title' => null])

<x-layouts.admin :title="$title">
    <div class="admin-page">
        {{ $slot }}
    </div>
</x-layouts.admin>
