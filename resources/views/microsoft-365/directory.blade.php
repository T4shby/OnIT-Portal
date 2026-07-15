@if($adminContext)
<x-admin-layout :title="'Microsoft 365 — '.$client->name">
    @include('admin.partials.header', [
        'title' => 'Microsoft 365 Directory',
        'action' => '<a href="'.route('admin.clients.edit', $client).'" class="cta-btn-ghost text-sm px-6 py-3">Back to client</a>',
    ])
    @include('microsoft-365._directory-body')
</x-admin-layout>
@else
<x-app-layout title="Microsoft 365" content-class="max-w-[96rem]">
    @include('microsoft-365._directory-body')
</x-app-layout>
@endif
