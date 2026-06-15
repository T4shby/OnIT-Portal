<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Notices',
        'action' => '<a href="'.route('admin.notices.create').'" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Add Notice</a>'
    ])
    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Title</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Published</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($notices as $notice)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $notice->title }}</td>
                        <td class="px-6 py-4 text-sm">{{ $notice->client->name }}</td>
                        <td class="px-6 py-4 text-sm">{{ $notice->published_at?->format('d M Y') ?? '—' }}</td>
                        <td class="px-6 py-4"><x-badge :variant="$notice->is_active ? 'success' : 'danger'">{{ $notice->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="px-6 py-4 text-right">
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
        @if($notices->hasPages())<div class="px-6 py-4 border-t">{{ $notices->links() }}</div>@endif
    </x-card>
</x-admin-layout>
