<x-admin-layout>
    <div class="mb-6">
        <a href="{{ route('admin.users.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; All companies</a>
    </div>

    @include('admin.partials.header', [
        'title' => 'On IT staff',
        'action' => '<a href="'.route('admin.users.create').'" class="cta-btn text-sm px-6 py-3">Add User</a>'
    ])

    <p class="portal-body-muted mb-6 text-sm">{{ $users->total() }} {{ str('user')->plural($users->total()) }} without a client assignment</p>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->role->label() }}</td>
                        <td>{{ $user->provisioned_by?->label() ?? 'Manual' }}</td>
                        <td>
                            <x-badge :variant="$user->is_active ? 'success' : 'danger'">{{ $user->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.users.edit', $user),
                                'deleteRoute' => route('admin.users.destroy', $user),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12"><x-empty-state title="No internal users" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($users->hasPages())
            <div class="border-t border-white/10 px-6 py-4">{{ $users->links() }}</div>
        @endif
    </div>
</x-admin-layout>
