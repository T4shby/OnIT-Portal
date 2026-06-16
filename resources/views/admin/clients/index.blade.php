<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<a href="'.route('admin.clients.create').'" class="cta-btn text-sm px-6 py-3">Add Client</a>'
    ])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Users</th>
                    <th>Entra sync</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clients as $client)
                    <tr>
                        <td>{{ $client->name }}</td>
                        <td>{{ $client->users_count }}</td>
                        <td>
                            @if($client->hasEntraSyncConfigured())
                                <x-badge variant="success">On</x-badge>
                            @else
                                <span class="text-white/40">Off</span>
                            @endif
                        </td>
                        <td><x-badge :variant="$client->is_active ? 'success' : 'danger'">{{ $client->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.clients.edit', $client),
                                'deleteRoute' => route('admin.clients.destroy', $client),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12"><x-empty-state title="No clients" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($clients->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $clients->links() }}</div>@endif
    </div>
</x-admin-layout>
