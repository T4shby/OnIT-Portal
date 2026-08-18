@php
    $forceSettingsOpen = $errors->any() || session()->has('success');
@endphp

<x-admin-layout>
    <div
        class="w-full max-w-full"
        x-data="{
            settingsOpen: {{ $forceSettingsOpen ? 'true' : 'false' }},
            init() {
                const lock = (open) => {
                    document.body.style.overflow = open ? 'hidden' : '';
                };
                this.$watch('settingsOpen', lock);
                lock(this.settingsOpen);
            }
        }"
        x-on:keydown.escape.window="settingsOpen = false"
    >
        {{-- Quiet header (no white plate title) --}}
        <div class="mb-8 flex flex-col gap-5 sm:mb-10 sm:flex-row sm:items-end sm:justify-between sm:gap-8">
            <div class="min-w-0">
                <div class="orange-rule"></div>
                <h1 class="m-0 font-condensed text-2xl font-bold uppercase tracking-tight text-white sm:text-[1.85rem]">
                    Integration Health
                </h1>
                <p class="portal-body-muted mt-4 max-w-xl text-sm leading-relaxed">
                    Live pipeline for background refresh across all clients. Updates every 5&nbsp;seconds.
                </p>
            </div>
            <div class="shrink-0">
                @include('admin.integration-health._settings-trigger')
            </div>
        </div>

        @if($errors->any())
            <x-alert type="danger" class="mb-8">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="mb-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
            <x-card class="!p-5 sm:!p-6">
                <p class="admin-stat-label text-xs">Queue pending</p>
                <p id="admin-queue-pending" class="admin-stat-value mt-2 text-2xl">{{ $integrationHealth['queue']['pending'] }}</p>
                <p id="admin-queue-detail" class="mt-3 break-words text-xs leading-relaxed text-white/50">
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
            <x-card class="!p-5 sm:!p-6">
                <p class="admin-stat-label text-xs">Stuck</p>
                <p class="admin-stat-value mt-2 text-2xl {{ ($integrationHealth['stuck_count'] ?? 0) > 0 ? 'text-rose-400' : '' }}">
                    {{ $integrationHealth['stuck_count'] ?? 0 }}
                </p>
            </x-card>
            <x-card class="!p-5 sm:!p-6">
                <p class="admin-stat-label text-xs">Waiting refresh</p>
                <p class="admin-stat-value mt-2 text-2xl {{ ($integrationHealth['due_count'] ?? 0) > 0 ? 'text-sky-300' : '' }}">
                    {{ $integrationHealth['due_count'] ?? 0 }}
                </p>
                <p class="mt-3 text-xs leading-relaxed text-white/50">Due, not started</p>
            </x-card>
            <x-card class="!p-5 sm:!p-6">
                <p class="admin-stat-label text-xs">Getting old</p>
                <p class="admin-stat-value mt-2 text-2xl {{ ($integrationHealth['aging_count'] ?? 0) > 0 ? 'text-amber-300' : '' }}">
                    {{ $integrationHealth['aging_count'] ?? 0 }}
                </p>
                <p class="mt-3 text-xs leading-relaxed text-white/50">Past freshness</p>
            </x-card>
        </div>

        @include('admin.partials.integration-health')

        @include('admin.integration-health._freshness-settings')
    </div>

    <script>
        (function () {
            var url = @json(route('admin.integration-health.live'));
            var timer = null;
            var formDirty = false;

            function formatOldest(seconds) {
                if (seconds === '' || seconds === null || typeof seconds === 'undefined') {
                    return '';
                }
                var mins = (Number(seconds) / 60).toFixed(1);
                return ' · oldest ' + mins + 'm';
            }

            function fmtMin(value) {
                var n = Number(value);
                if (!isFinite(n)) {
                    return String(value || '');
                }
                return String(n.toFixed(1)).replace(/\.0$/, '').replace(/(\.\d)0$/, '$1');
            }

            function formTiming() {
                var read = function (name, fallback) {
                    var el = document.querySelector('[name="freshness[' + name + ']"]');
                    return el && el.value !== '' ? el.value : fallback;
                };
                return {
                    hot: read('hot_minutes', '2.5'),
                    workIdle: read('work_idle_minutes', '60'),
                    offIdle: read('off_hours_idle_minutes', '60'),
                    presence: read('presence_minutes', '15'),
                    start: read('work_start', '07:00'),
                    end: read('work_end', '19:00'),
                    tz: read('timezone', 'Europe/London'),
                };
            }

            function applyConfiguredFromForm(root) {
                var cfg = formTiming();
                var line = (root || document).querySelector('#ih-configured-timing');
                if (!line) {
                    return;
                }
                line.textContent =
                    'Timing settings: Fast ' + fmtMin(cfg.hot) + 'm' +
                    ' · Idle business ' + fmtMin(cfg.workIdle) + 'm' +
                    ' · Idle outside ' + fmtMin(cfg.offIdle) + 'm' +
                    ' · Active session ' + fmtMin(cfg.presence) + 'm' +
                    ' · Hours ' + cfg.start + '-' + cfg.end +
                    ' ' + cfg.tz +
                    (formDirty ? ' (unsaved - save to apply)' : '');
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
                        applyConfiguredFromForm(next);
                    })
                    .catch(function () {
                        /* keep last good snapshot */
                    });
            }

            document.querySelectorAll('[name^="freshness["]').forEach(function (el) {
                el.addEventListener('input', function () {
                    formDirty = true;
                    applyConfiguredFromForm(document.getElementById('integration-health-live'));
                });
                el.addEventListener('change', function () {
                    formDirty = true;
                    applyConfiguredFromForm(document.getElementById('integration-health-live'));
                });
            });

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
