<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Integration Health'])

    <p class="portal-body-muted text-sm mb-6 max-w-3xl">
        Live pipeline for SuperOps / M365 / Entra refreshes: prewarm heartbeat, queue workers, job flags, and blockers.
        Updates every 5 seconds while this tab is open.
    </p>

    <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
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
                @if(($integrationHealth['queue']['reserved'] ?? 0) > 0)
                    · reserved {{ $integrationHealth['queue']['reserved'] }}
                @endif
            </p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Stuck</p>
            <p class="admin-stat-value {{ ($integrationHealth['stuck_count'] ?? 0) > 0 ? 'text-rose-400' : '' }}">
                {{ $integrationHealth['stuck_count'] ?? 0 }}
            </p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Due requeue</p>
            <p class="admin-stat-value {{ ($integrationHealth['due_count'] ?? 0) > 0 ? 'text-sky-300' : '' }}">
                {{ $integrationHealth['due_count'] ?? 0 }}
            </p>
            <p class="mt-2 text-xs text-white/50">Past SuperOps requeue age, not yet running</p>
        </x-card>
        <x-card>
            <p class="admin-stat-label">Aging</p>
            <p class="admin-stat-value {{ ($integrationHealth['aging_count'] ?? 0) > 0 ? 'text-amber-300' : '' }}">
                {{ $integrationHealth['aging_count'] ?? 0 }}
            </p>
            <p class="mt-2 text-xs text-white/50">Past client freshness target</p>
        </x-card>
    </div>

    @include('admin.partials.integration-health')

    <script>
        (function () {
            var url = @json(route('admin.integration-health.live'));
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
                        formatOldest(el.dataset.queueOldest) +
                        (el.dataset.dueCount && Number(el.dataset.dueCount) > 0
                            ? ' · due ' + el.dataset.dueCount
                            : '') +
                        (el.dataset.agingCount && Number(el.dataset.agingCount) > 0
                            ? ' · aging ' + el.dataset.agingCount
                            : '');
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
