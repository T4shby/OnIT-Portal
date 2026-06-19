<x-admin-layout>
    @include('admin.partials.header', [
        'title' => $organisationName.' team',
        'action' => '<a href="'.route('admin.team.create').'" class="cta-btn text-sm px-6 py-3">Add team member</a>'
    ])

    <p class="portal-body-muted mb-6 text-sm">
        On IT technicians and account managers who manage the admin portal.
        Customer portal users are managed under <a href="{{ route('admin.users.index') }}" class="hover:text-onit">Users</a>.
    </p>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Assigned clients</th>
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
                        <td>
                            @if($user->role === \App\Enums\UserRole::AccountManager)
                                @if($user->assignedClients->isEmpty())
                                    <span class="portal-body-muted text-sm">None assigned</span>
                                @else
                                    <span class="text-sm">{{ $user->assignedClients->sortBy('name')->pluck('name')->join(', ') }}</span>
                                @endif
                            @else
                                <span class="portal-body-muted text-sm">—</span>
                            @endif
                        </td>
                        <td>
                            <x-badge :variant="$user->is_active ? 'success' : 'danger'">{{ $user->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.team.edit', $user),
                                'deleteRoute' => $user->id !== auth()->id() ? route('admin.team.destroy', $user) : null,
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12"><x-empty-state title="No team members yet" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($users->hasPages())
            <div class="border-t border-white/10 px-6 py-4">{{ $users->links() }}</div>
        @endif
    </div>
</x-admin-layout>
