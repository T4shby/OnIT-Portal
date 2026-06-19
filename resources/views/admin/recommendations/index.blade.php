<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Recommendations',
        'action' => '<a href="'.route('admin.recommendations.create').'" class="cta-btn text-sm px-6 py-3">Add Recommendation</a>'
    ])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Client</th>
                    <th>Priority</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recommendations as $rec)
                    <tr>
                        <td>{{ $rec->title }}</td>
                        <td>{{ $rec->client->name }}</td>
                        <td><x-badge :variant="$rec->priorityColor()">{{ ucfirst($rec->priority) }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.recommendations.edit', $rec),
                                'deleteRoute' => route('admin.recommendations.destroy', $rec),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-12"><x-empty-state title="No recommendations" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($recommendations->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $recommendations->links() }}</div>@endif
    </div>
</x-admin-layout>
