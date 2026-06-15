<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Opportunities',
        'action' => '<a href="'.route('admin.opportunities.create').'" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Add Opportunity</a>'
    ])
    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Title</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($opportunities as $opp)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $opp->title }}</td>
                        <td class="px-6 py-4 text-sm">{{ $opp->client->name }}</td>
                        <td class="px-6 py-4"><x-badge :variant="$opp->statusColor()">{{ str_replace('_', ' ', ucfirst($opp->status)) }}</x-badge></td>
                        <td class="px-6 py-4 text-right">
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
        @if($opportunities->hasPages())<div class="px-6 py-4 border-t">{{ $opportunities->links() }}</div>@endif
    </x-card>
</x-admin-layout>
