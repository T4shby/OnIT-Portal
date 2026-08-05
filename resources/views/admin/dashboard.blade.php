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
            <p class="admin-stat-label">Queue pending</p>
            <p id="admin-queue-pending" class="admin-stat-value">{{ $integrationHealth['queue']['pending'] }}</p>
            <p id="admin-queue-detail" class="mt-2 text-xs text-white/50">
                high {{ $integrationHealth['queue']['high'] }}
                · default {{ $integrationHealth['queue']['default'] }}
                · failed {{ $integrationHealth['queue']['failed'] }}
                @if($integrationHealth['queue']['oldest_pending_seconds'] !== null)
                    · oldest {{ number_format($integrationHealth['queue']['oldest_pending_seconds'] / 60, 1) }}m
                @endif
            </p>
        </x-card>
    </div>

    {{-- Technician-only: live-polled every 5s (not shown to client portal users) --}}
    @include('admin.partials.integration-health')

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

    <script>
        (function () {
            var url = @json(route('admin.integration-health'));
            var timer = null;

            function formatOldest(seconds) {
                if (seconds === '' || seconds === null || typeof seconds === 'undefined') {
                    return '';
                }
                var mins = (Number(seconds) / 60).toFixed(1);
                return ' · oldest ' + mins + 'm';
            }

            function applyQueueFrom(el) {
                if (!el || !el.dataset) {
                    return;
                }
                var pending = document.getElementById('admin-queue-pending');
                var detail = document.getElementById('admin-queue-detail');
                if (pending) {
                    pending.textContent = el.dataset.queuePending || '0';
                }
                if (detail) {
                    detail.textContent =
                        'high ' + (el.dataset.queueHigh || '0') +
                        ' · default ' + (el.dataset.queueDefault || '0') +
                        ' · failed ' + (el.dataset.queueFailed || '0') +
                        formatOldest(el.dataset.queueOldest);
                }
            }

            function poll() {
                fetch(url, {
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('health poll failed');
                        }
                        return res.text();
                    })
                    .then(function (html) {
                        var wrap = document.createElement('div');
                        wrap.innerHTML = html.trim();
                        var next = wrap.firstElementChild;
                        var current = document.getElementById('integration-health-live');
                        if (!next || !current || !current.parentNode) {
                            return;
                        }
                        current.parentNode.replaceChild(next, current);
                        applyQueueFrom(next);
                    })
                    .catch(function () {
                        /* keep last good snapshot */
                    });
            }

            timer = setInterval(poll, 5000);
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                } else if (!timer) {
                    poll();
                    timer = setInterval(poll, 5000);
                }
            });
        })();
    </script>
</x-admin-layout>
