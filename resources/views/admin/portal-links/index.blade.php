<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Portal Links',
        'action' => '<a href="'.route('admin.portal-links.create').'" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Add Link</a>'
    ])

    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Order</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($links as $link)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $link->name }}</td>
                        <td class="px-6 py-4 text-sm text-slate-600">{{ $link->link_type?->label() ?? 'External' }}</td>
                        <td class="px-6 py-4 text-sm">{{ $link->client?->name ?? 'Global' }}</td>
                        <td class="px-6 py-4 text-sm">{{ $link->display_order }}</td>
                        <td class="px-6 py-4"><x-badge :variant="$link->is_active ? 'success' : 'danger'">{{ $link->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="px-6 py-4 text-right">
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
        @if($links->hasPages())<div class="px-6 py-4 border-t">{{ $links->links() }}</div>@endif
    </x-card>
</x-admin-layout>
