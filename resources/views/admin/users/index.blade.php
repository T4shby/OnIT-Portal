<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Users',
        'action' => '<a href="'.route('admin.users.create').'" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Add User</a>'
    ])

    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($users as $user)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $user->name }}</td>
                        <td class="px-6 py-4 text-sm">{{ $user->email }}</td>
                        <td class="px-6 py-4 text-sm">{{ $user->role->label() }}</td>
                        <td class="px-6 py-4 text-sm">{{ $user->client?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.users.edit', $user),
                                'deleteRoute' => route('admin.users.destroy', $user),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12"><x-empty-state title="No users" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($users->hasPages())<div class="px-6 py-4 border-t">{{ $users->links() }}</div>@endif
    </x-card>
</x-admin-layout>
