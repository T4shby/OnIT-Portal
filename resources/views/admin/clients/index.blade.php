<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<a href="'.route('admin.clients.create').'" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Add Client</a>'
    ])

    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Users</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Entra sync</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($clients as $client)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $client->name }}</td>
                        <td class="px-6 py-4 text-sm">{{ $client->users_count }}</td>
                        <td class="px-6 py-4 text-sm">
                            @if($client->hasEntraSyncConfigured())
                                <x-badge variant="success">On</x-badge>
                            @else
                                <span class="text-slate-400">Off</span>
                            @endif
                        </td>
                        <td class="px-6 py-4"><x-badge :variant="$client->is_active ? 'success' : 'danger'">{{ $client->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="px-6 py-4 text-right">
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
        @if($clients->hasPages())<div class="px-6 py-4 border-t">{{ $clients->links() }}</div>@endif
    </x-card>
</x-admin-layout>
