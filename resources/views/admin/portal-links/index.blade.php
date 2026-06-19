<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Portal Links',
        'action' => '<a href="'.route('admin.portal-links.create').'" class="cta-btn text-sm px-6 py-3">Add Link</a>'
    ])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($links as $link)
                    <tr>
                        <td>{{ $link->name }}</td>
                        <td>{{ $link->link_type?->label() ?? 'External' }}</td>
                        <td>{{ $link->client?->name ?? 'Global' }}</td>
                        <td>{{ $link->display_order }}</td>
                        <td><x-badge :variant="$link->is_active ? 'success' : 'danger'">{{ $link->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.portal-links.edit', $link),
                                'deleteRoute' => route('admin.portal-links.destroy', $link),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12"><x-empty-state title="No portal links" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($links->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $links->links() }}</div>@endif
    </div>
</x-admin-layout>
