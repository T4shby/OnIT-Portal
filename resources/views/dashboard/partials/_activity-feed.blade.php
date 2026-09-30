@php
    $variant = $variant ?? 'glance';
    $now = \Illuminate\Support\Carbon::now('Europe/London');
    $weekStart = $now->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->startOfDay();
    $weekEnd = $weekStart->copy()->addDays(4)->endOfDay();
    $prepared = [];
    foreach ($items as $index => $item) {
        $ts = null;
        $weekday = null;
        $atLabel = null;
        try {
            if (filled($item['at'] ?? null)) {
                $at = \Illuminate\Support\Carbon::parse($item['at'])->timezone('Europe/London');
                $ts = $at->timestamp;
                $weekday = $at->dayOfWeekIso;
                $atLabel = $at->format('d M · H:i');
            }
        } catch (\Throwable) {
            $ts = null;
        }
        $actions = [];
        foreach (is_array($item['actions'] ?? null) ? $item['actions'] : [] as $action) {
            if (! is_array($action)) {
                continue;
            }
            $actions[] = [
                'title' => (string) ($action['title'] ?? 'Update'),
                'badge' => filled($action['badge'] ?? null) ? (string) $action['badge'] : null,
            ];
        }
        $source = (string) ($item['source'] ?? '');
        $prepared[] = [
            'key' => $index,
            'ts' => $ts,
            'weekday' => $weekday,
            'atLabel' => $atLabel,
            'title' => (string) ($item['title'] ?? 'Update'),
            'text' => (string) ($item['text'] ?? ''),
            'badge' => filled($item['badge'] ?? null) ? (string) $item['badge'] : null,
            'ref' => filled($item['ref'] ?? null) ? (string) $item['ref'] : null,
            'source' => match ($source) {
                'support' => 'Support',
                'security' => 'Security',
                'backup' => 'Backup',
                default => 'Update',
            },
            'href' => filled($item['href'] ?? null) ? (string) $item['href'] : null,
            'detailUrl' => filled($item['detail_url'] ?? null) ? (string) $item['detail_url'] : null,
            'body' => filled($item['body'] ?? null) ? (string) $item['body'] : null,
            'actions' => $actions,
        ];
    }
    $config = [
        'variant' => $variant,
        'items' => $prepared,
        'bounds' => [
            'today' => [$now->copy()->startOfDay()->timestamp, $now->copy()->endOfDay()->timestamp],
            'week' => [$weekStart->timestamp, $weekEnd->timestamp],
            'month' => [$now->copy()->startOfMonth()->startOfDay()->timestamp, $now->copy()->endOfMonth()->endOfDay()->timestamp],
        ],
    ];
@endphp
<style>
    [x-cloak] { display: none !important; }
    .activity-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .activity-filters { display: flex; flex-wrap: wrap; gap: 6px; }
    .activity-chip, .activity-size {
        border: 1px solid {{ $variant === 'report' ? '#ffd2b0' : '#0f3048' }};
        background: transparent;
        color: {{ $variant === 'report' ? '#071f2e' : '#fff' }};
        font-size: 12px;
        font-weight: 600;
        padding: 6px 10px;
        cursor: pointer;
    }
    .activity-chip.is-on { background: #FF7000; border-color: #FF7000; color: #fff; }
    .activity-size { padding: 6px 8px; }
    .activity-row {
        display: grid;
        grid-template-columns: 6.5rem minmax(0, 1.5fr) minmax(0, 1fr) auto;
        gap: 6px 14px;
        align-items: center;
        padding: 8px 4px;
        border-top: 1px solid {{ $variant === 'report' ? '#f0e6dc' : '#0f3048' }};
        cursor: pointer;
    }
    .activity-row:hover { background: {{ $variant === 'report' ? '#fff8f3' : 'rgba(255,255,255,.04)' }}; }
    .activity-subject { font-size: 13px; font-weight: 600; line-height: 1.3; color: {{ $variant === 'report' ? '#071f2e' : '#fff' }}; }
    .activity-meta, .activity-action { font-size: 12px; line-height: 1.35; color: {{ $variant === 'report' ? '#666' : 'rgba(255,255,255,.72)' }}; }
    .activity-detail { grid-column: 1 / -1; padding: 8px 10px 10px; background: {{ $variant === 'report' ? '#fff8f3' : 'rgba(255,255,255,.04)' }}; border: 1px solid {{ $variant === 'report' ? '#ffd2b0' : 'rgba(255,112,0,.35)' }}; }
    .glance-activity-badge, .rp-activity-badge { margin-top: 0; }
    @media (max-width: 720px) {
        .activity-row { grid-template-columns: 1fr auto; align-items: start; }
        .activity-subject, .activity-action { grid-column: 1 / -1; }
    }
</style>
<div
    x-data="onitActivityTimeline({{ \Illuminate\Support\Js::from($config) }})"
>
    <div class="activity-toolbar">
        <div class="activity-filters" role="group" aria-label="When">
            <button type="button" class="activity-chip" :class="{ 'is-on': range === 'today' }" @click="setRange('today')">Today</button>
            <button type="button" class="activity-chip" :class="{ 'is-on': range === 'week' }" @click="setRange('week')">This working week</button>
            <button type="button" class="activity-chip" :class="{ 'is-on': range === 'month' }" @click="setRange('month')">This working month</button>
        </div>
        <label class="activity-meta" style="display:inline-flex;align-items:center;gap:8px">
            Show
            <select class="activity-size" x-model.number="pageSize" @change="shown = pageSize" aria-label="How many to show">
                <option value="5">5</option>
                <option value="10">10</option>
            </select>
        </label>
    </div>
    <p class="activity-meta" style="margin:0 0 8px" x-text="summary"></p>
    <div x-ref="list">
        <template x-for="(item, index) in visible" :key="item.key">
            <div class="activity-row" :data-index="index" @click="toggle(item)">
                <div class="activity-meta" x-text="item.atLabel || item.source"></div>
                <div style="min-width:0">
                    <div class="activity-meta" x-show="item.ref" x-text="item.source + (item.ref ? ' · ' + item.ref : '')"></div>
                    <div class="activity-subject" x-text="item.title"></div>
                </div>
                <div class="activity-action" x-text="item.text"></div>
                <div>
                    <span x-show="item.badge" class="{{ $variant === 'report' ? 'rp-activity-badge' : 'glance-activity-badge' }}" x-text="item.badge"></span>
                </div>
                <div class="activity-detail" x-show="openKey === item.key" x-cloak @click.stop>
                    <p class="activity-action" style="margin:0 0 8px" x-show="item.body" x-text="item.body"></p>
                    <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px" x-show="item.actions.length">
                        <template x-for="(action, actionIndex) in item.actions" :key="actionIndex">
                            <li class="activity-subject">
                                <span x-text="action.title"></span>
                                <span x-show="action.badge" class="{{ $variant === 'report' ? 'rp-activity-badge' : 'glance-activity-badge' }}" x-text="action.badge"></span>
                            </li>
                        </template>
                    </ul>
                    <p class="activity-meta" style="margin:0" x-show="item.loading">Loading this action...</p>
                    <p class="activity-meta" style="margin:0" x-show="item.error">We could not load the detail just now.</p>
                    <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px" x-show="item.rows && item.rows.length">
                        <template x-for="(row, rowIndex) in item.rows" :key="rowIndex">
                            <li>
                                <div class="activity-subject">
                                    <span x-text="row.title"></span>
                                    <span class="activity-meta" x-show="row.at" x-text="row.at ? ' · ' + row.at : ''"></span>
                                </div>
                                <p class="activity-action" style="margin:2px 0 0" x-text="row.text"></p>
                            </li>
                        </template>
                    </ul>
                    <a x-show="item.href" :href="item.href" @click.stop style="display:inline-block;margin-top:8px;color:#FF7000;font-size:12px;font-weight:700">Open the full record</a>
                </div>
            </div>
        </template>
    </div>
    <p class="activity-meta" style="margin:12px 0 0" x-show="filteredCount === 0" x-cloak>Nothing in this period. Try a wider range.</p>
    <button type="button" class="activity-chip" style="margin-top:10px" x-show="canShowMore" x-cloak @click="showMore()">Show more</button>
</div>
<script>
    function onitActivityTimeline(config) {
        return {
            range: 'week',
            pageSize: 5,
            shown: 5,
            openKey: null,
            items: config.items || [],
            bounds: config.bounds || {},
            get filtered() {
                return this.items.filter((item) => this.matches(item));
            },
            get visible() {
                return this.filtered.slice(0, this.shown);
            },
            get filteredCount() {
                return this.filtered.length;
            },
            get canShowMore() {
                return this.filteredCount > this.shown && this.shown < 10;
            },
            get summary() {
                const labels = { today: 'today', week: 'this working week', month: 'this working month' };
                const count = this.filteredCount;
                if (count === 0) {
                    return 'Nothing ' + (labels[this.range] || 'in this period') + '.';
                }
                const visible = Math.min(this.shown, count);
                return 'Showing ' + visible + ' of ' + count + ' · ' + (labels[this.range] || '');
            },
            matches(item) {
                if (!item.ts) {
                    return true;
                }
                const bound = this.bounds[this.range];
                if (!bound) {
                    return true;
                }
                if (item.ts < bound[0] || item.ts > bound[1]) {
                    return false;
                }
                if (this.range !== 'today' && item.weekday > 5) {
                    return false;
                }
                return true;
            },
            setRange(range) {
                this.range = range;
                this.shown = this.pageSize;
                this.openKey = null;
            },
            showMore() {
                const start = this.shown;
                this.shown = Math.min(10, this.filteredCount);
                this.$nextTick(() => {
                    const row = this.$refs.list.querySelector('[data-index="' + start + '"]');
                    if (row) {
                        row.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            },
            toggle(item) {
                if (this.openKey === item.key) {
                    this.openKey = null;
                    return;
                }
                this.openKey = item.key;
                if (!item.detailUrl || item.loaded || item.loading) {
                    return;
                }
                item.loading = true;
                item.error = false;
                fetch(item.detailUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('unavailable');
                        }
                        return response.json();
                    })
                    .then((data) => {
                        item.rows = Array.isArray(data.actions) ? data.actions : [];
                        item.loaded = true;
                    })
                    .catch(() => {
                        item.error = true;
                        item.loaded = true;
                    })
                    .finally(() => {
                        item.loading = false;
                    });
            },
        };
    }
</script>
