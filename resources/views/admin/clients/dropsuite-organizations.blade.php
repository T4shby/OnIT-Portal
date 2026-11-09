<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Dropsuite organisations',
        'action' => '<div class="flex flex-wrap gap-2 justify-end">'
            .'<a href="'.route('admin.clients.dropsuite-organizations', array_filter(['filter' => $filter, 'q' => $search, 'refresh' => 1])).'" class="cta-btn-ghost text-sm px-5 py-3">Refresh from Dropsuite</a>'
            .'<a href="'.route('admin.clients.index').'" class="cta-btn-ghost text-sm px-5 py-3">← Clients</a>'
            .'</div>',
    ])

    <div class="mb-6 border border-white/10 bg-white/[0.02] p-5 space-y-3">
        <p class="portal-body-muted text-sm leading-relaxed">
            This page pulls every organisation from Dropsuite (<span class="font-mono text-white/70">GET /users</span>),
            then you filter to live portal clients. The number to save is the short
            <strong class="text-white/80">organization_id</strong> (YorPower is 6182).
            The id shown in Dropsuite, like 360787-12, is a user id and will not work.
        </p>
        <form method="get" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[12rem]">
                <label class="portal-label mb-1 block" for="dropsuite-filter">Show</label>
                <select id="dropsuite-filter" name="filter" class="admin-input">
                    <option value="live" @selected($filter === 'live')>Live portal matches</option>
                    <option value="unmapped" @selected($filter === 'unmapped')>Unmapped Dropsuite orgs</option>
                    <option value="all" @selected($filter === 'all')>Everything from Dropsuite</option>
                </select>
            </div>
            <div class="min-w-[14rem] flex-1">
                <label class="portal-label mb-1 block" for="dropsuite-q">Search</label>
                <input id="dropsuite-q" type="search" name="q" value="{{ $search }}" class="admin-input" placeholder="Name or organization_id">
            </div>
            <button type="submit" class="cta-btn text-sm px-5 py-3">Filter</button>
        </form>
    </div>

    @if($error)
        <p class="mb-6 border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">{{ $error }}</p>
    @endif

    <div class="admin-table-wrap mb-10">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Dropsuite organisation</th>
                    <th>organization_id</th>
                    <th>Users</th>
                    <th>Portal client</th>
                    <th class="text-right">Map</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="font-medium text-white">{{ $row['name'] }}</td>
                        <td class="font-mono text-xs text-white/70">{{ $row['organization_id'] }}</td>
                        <td class="text-white/60">{{ $row['user_count'] }}</td>
                        <td>
                            @if($row['mapped_client'])
                                <a href="{{ route('admin.clients.edit', $row['mapped_client']) }}" class="text-emerald-300 hover:text-white">
                                    {{ $row['mapped_client']->name }}
                                </a>
                                @unless($row['mapped_client']->is_active)
                                    <span class="ml-1 text-xs text-white/40">inactive</span>
                                @endunless
                            @elseif($row['suggested_client'])
                                <span class="text-amber-200">Suggested: {{ $row['suggested_client']->name }}</span>
                            @else
                                <span class="text-white/35">No live match</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($row['mapped_client'])
                                <a href="{{ route('admin.clients.edit', $row['mapped_client']) }}" class="cta-btn-ghost text-sm px-3 py-2 inline-flex">Edit</a>
                            @else
                                <form method="post" action="{{ route('admin.clients.dropsuite-organizations.assign') }}" class="flex flex-wrap items-center justify-end gap-2">
                                    @csrf
                                    <input type="hidden" name="organization_id" value="{{ $row['organization_id'] }}">
                                    <input type="hidden" name="filter" value="{{ $filter }}">
                                    <input type="hidden" name="q" value="{{ $search }}">
                                    <select name="client_id" class="admin-input text-sm max-w-[14rem]" required>
                                        <option value="">Live client</option>
                                        @foreach($assignableClients as $client)
                                            <option value="{{ $client->id }}" @selected($row['suggested_client']?->id === $client->id)>
                                                {{ $client->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="cta-btn text-sm px-3 py-2">Link</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12">
                            <x-empty-state
                                title="No organisations in this filter"
                                description="Switch to Everything from Dropsuite, or Refresh from Dropsuite if the list is empty."
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <section class="border border-white/10 bg-white/[0.02] p-5">
        <p class="portal-label mb-2">Live portal clients with no Dropsuite id</p>
        <p class="portal-body-muted text-xs mb-4 leading-relaxed">
            These clients are active in the portal but not mapped yet. Link them from the table above, or they are not in Dropsuite.
        </p>
        @if($unmappedLiveClients->isEmpty())
            <p class="text-sm text-white/50">Every live client you can see already has an organization_id, or none are live.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach($unmappedLiveClients as $client)
                    <li>
                        <a href="{{ route('admin.clients.edit', $client) }}" class="text-white hover:text-onit">{{ $client->name }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-admin-layout>
