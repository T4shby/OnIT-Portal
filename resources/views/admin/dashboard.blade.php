<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Admin Dashboard'])

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
        <x-card>
            <p class="text-sm text-slate-500">Clients</p>
            <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['clients'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Users</p>
            <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['users'] }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Active Notices</p>
            <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['notices'] }}</p>
        </x-card>
    </div>

    <x-card>
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Recent Activity</h2>
        @if($recentActivity->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Action</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">User</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach($recentActivity as $log)
                            <tr>
                                <td class="px-4 py-3 text-sm">{{ $log->action }}</td>
                                <td class="px-4 py-3 text-sm">{{ $log->user?->name ?? 'System' }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $log->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-empty-state title="No activity yet" />
        @endif
    </x-card>
</x-admin-layout>
