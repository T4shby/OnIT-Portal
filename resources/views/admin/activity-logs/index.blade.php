<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Activity Logs'])

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>User</th>
                    <th>Client</th>
                    <th>IP</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->user?->name ?? '-' }}</td>
                        <td>{{ $log->client?->name ?? '-' }}</td>
                        <td class="text-white/50">{{ $log->ip_address }}</td>
                        <td class="text-white/50">{{ $log->created_at->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12"><x-empty-state title="No activity logs" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($logs->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $logs->links() }}</div>@endif
    </div>
</x-admin-layout>
