<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Opportunities',
        'action' => '<a href="'.route('admin.opportunities.create').'" class="cta-btn text-sm px-6 py-3">Add Opportunity</a>'
    ])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($opportunities as $opp)
                    <tr>
                        <td>{{ $opp->title }}</td>
                        <td>{{ $opp->client->name }}</td>
                        <td><x-badge :variant="$opp->statusColor()">{{ str_replace('_', ' ', ucfirst($opp->status)) }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.opportunities.edit', $opp),
                                'deleteRoute' => route('admin.opportunities.destroy', $opp),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-12"><x-empty-state title="No opportunities" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($opportunities->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $opportunities->links() }}</div>@endif
    </div>
</x-admin-layout>
