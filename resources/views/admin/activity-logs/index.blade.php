<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Activity Logs'])

    <x-card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">User</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">IP</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Time</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $log->action }}</td>
                        <td class="px-6 py-4 text-sm">{{ $log->user?->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-sm">{{ $log->client?->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-sm text-slate-500">{{ $log->ip_address }}</td>
                        <td class="px-6 py-4 text-sm text-slate-500">{{ $log->created_at->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12"><x-empty-state title="No activity logs" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($logs->hasPages())<div class="px-6 py-4 border-t">{{ $logs->links() }}</div>@endif
    </x-card>
</x-admin-layout>
