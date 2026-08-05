<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Admin Dashboard'])

    <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <p class="admin-stat-label">Clients</p>
            <p class="admin-stat-value">{{ $stats['clients'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Users</p>
            <p class="admin-stat-value">{{ $stats['users'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Active Notices</p>
            <p class="admin-stat-value">{{ $stats['notices'] }}</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Refresh pipeline</p>
            <p class="admin-stat-value">
                @if(($healthSummary['stuck'] ?? 0) > 0)
                    <span class="text-rose-400">{{ $healthSummary['stuck'] }} stuck</span>
                @elseif(($healthSummary['due'] ?? 0) > 0)
                    <span class="text-sky-300">{{ $healthSummary['due'] }} due</span>
                @elseif(($healthSummary['pending'] ?? 0) > 0)
                    <span class="text-onit">{{ $healthSummary['pending'] }} queued</span>
                @else
                    OK
                @endif
            </p>
            <p class="mt-2 text-xs text-white/50">
                pending {{ $healthSummary['pending'] ?? 0 }}
                · aging {{ $healthSummary['aging'] ?? 0 }}
            </p>
            <a href="{{ route('admin.integration-health.index') }}" class="mt-3 inline-block text-xs text-onit hover:text-white uppercase tracking-wide">
                Open Integration Health →
            </a>
        </x-card>
    </div>

    <div class="admin-table-wrap">
        <div class="border-b border-white/10 px-6 py-4">
            <h2 class="admin-section-title mb-0">Recent Activity</h2>
        </div>
        @if($recentActivity->isNotEmpty())
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>User</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentActivity as $log)
                        <tr>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->user?->name ?? 'System' }}</td>
                            <td class="text-white/50">{{ $log->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="px-6 py-12"><x-empty-state title="No activity yet" /></div>
        @endif
    </div>
</x-admin-layout>
