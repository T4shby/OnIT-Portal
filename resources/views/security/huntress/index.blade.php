@if($adminContext)
    <x-admin-layout :title="'Huntress — '.$client->name">
        @include('admin.partials.header', [
            'title' => 'Huntress security — '.$client->name,
            'action' => '<a href="'.route('admin.clients.edit', $client).'" class="cta-btn-ghost text-sm px-6 py-3">Back to client</a>',
        ])
        @include('security.huntress._list-body')
    </x-admin-layout>
@else
    <x-app-layout title="Huntress security" content-class="max-w-[96rem]">
        @include('security.huntress._list-body')
    </x-app-layout>
@endif
