<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Notices',
        'action' => '<a href="'.route('admin.notices.create').'" class="cta-btn text-sm px-6 py-3">Add Notice</a>'
    ])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Client</th>
                    <th>Published</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $notice)
                    <tr>
                        <td>{{ $notice->title }}</td>
                        <td>{{ $notice->client->name }}</td>
                        <td>{{ $notice->published_at?->format('d M Y') ?? '-' }}</td>
                        <td><x-badge :variant="$notice->is_active ? 'success' : 'danger'">{{ $notice->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.notices.edit', $notice),
                                'deleteRoute' => route('admin.notices.destroy', $notice),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12"><x-empty-state title="No notices" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($notices->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $notices->links() }}</div>@endif
    </div>
</x-admin-layout>
