@if($adminContext)
    <x-admin-layout :title="'Case — '.$client->name">
        @include('admin.partials.header', [
            'title' => 'Security case',
            'action' => '<a href="'.e($indexRoute).'" class="cta-btn-ghost text-sm px-6 py-3">Back to list</a>',
        ])
        @include('security.huntress._show-body')
    </x-admin-layout>
@else
    <x-app-layout title="Security case" content-class="max-w-[96rem]">
        @include('security.huntress._show-body')
    </x-app-layout>
@endif
