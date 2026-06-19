@php
    $previewLimit = 5;
@endphp

<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Users'])

    <p class="portal-body-muted mb-6 text-sm">Select a company to view and manage its portal users.</p>

    <div class="admin-client-tiles">
        @foreach($clients as $client)
            <a href="{{ route('admin.clients.users.index', $client) }}" class="admin-client-tile group">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="portal-card-title text-base leading-tight group-hover:text-onit">{{ $client->name }}</h2>
                    <span class="shrink-0 font-condensed text-xs font-bold uppercase tracking-wider text-white/40">
                        {{ $client->users_count }} {{ str('user')->plural($client->users_count) }}
                    </span>
                </div>

                @if($client->active_users_count < $client->users_count)
                    <p class="portal-body-muted mt-1 text-xs">{{ $client->active_users_count }} active</p>
                @endif

                @if($client->users_count > 0)
                    <ul class="mt-4 space-y-1 border-t border-white/10 pt-4">
                        @foreach($client->users as $user)
                            <li class="truncate text-xs text-white/60">{{ $user->name }}</li>
                        @endforeach
                    </ul>
                    @if($client->users_count > $previewLimit)
                        <p class="mt-3 font-condensed text-xs font-bold uppercase tracking-wide text-onit">
                            View all {{ $client->users_count }} users →
                        </p>
                    @else
                        <p class="mt-3 font-condensed text-xs font-bold uppercase tracking-wide text-onit opacity-0 transition group-hover:opacity-100">
                            Open →
                        </p>
                    @endif
                @else
                    <p class="portal-body-muted mt-4 border-t border-white/10 pt-4 text-xs">No users yet</p>
                    <p class="mt-2 font-condensed text-xs font-bold uppercase tracking-wide text-onit">Add users →</p>
                @endif
            </a>
        @endforeach
    </div>

    @if($clients->isEmpty())
        <div class="admin-table-wrap">
            <div class="px-6 py-12"><x-empty-state title="No clients yet" /></div>
        </div>
    @endif
</x-admin-layout>
