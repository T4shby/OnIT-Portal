<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Users'])

    <p class="portal-body-muted mb-6 text-sm">Select a company to view and manage its portal users.</p>

    @if($clients->isNotEmpty())
        <div class="admin-client-tiles">
            @foreach($clients as $client)
                @php
                    $initials = collect(preg_split('/\s+/', trim($client->name)))
                        ->filter()
                        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
                        ->take(2)
                        ->join('');
                @endphp
                <a href="{{ route('admin.clients.users.index', $client) }}" class="admin-client-tile group">
                    <div class="flex items-start justify-between gap-3">
                        <span class="admin-client-tile__avatar" aria-hidden="true">{{ $initials }}</span>
                        <span class="admin-client-tile__count">
                            {{ $client->users_count }} {{ str('user')->plural($client->users_count) }}
                        </span>
                    </div>

                    <h2 class="admin-client-tile__name line-clamp-3">{{ $client->name }}</h2>

                    <div class="flex items-end justify-end pt-2">
                        @if($client->users_count === 0)
                            <span class="admin-client-tile__action">Add users →</span>
                        @else
                            <span class="admin-client-tile__action">
                                @if($client->active_users_count < $client->users_count)
                                    {{ $client->active_users_count }} active ·
                                @endif
                                Open →
                            </span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="admin-table-wrap">
            <div class="px-6 py-12"><x-empty-state title="No clients yet" /></div>
        </div>
    @endif
</x-admin-layout>
