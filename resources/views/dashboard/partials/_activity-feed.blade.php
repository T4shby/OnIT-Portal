@php
    $variant = $variant ?? 'glance';
    $badgeClass = $variant === 'report' ? 'rp-activity-badge' : 'glance-activity-badge';
    $mutedClass = $variant === 'report' ? 'rp-muted' : 'glance-muted';
    $titleColor = $variant === 'report' ? '#071f2e' : '#fff';
    $lineColor = $variant === 'report' ? '#ffd2b0' : 'rgba(255,112,0,.55)';
    $panelBg = $variant === 'report' ? '#fff8f3' : 'rgba(255,255,255,.04)';
    $panelBorder = $variant === 'report' ? '#ffd2b0' : 'rgba(255,112,0,.35)';
@endphp
<style>
    [x-cloak] { display: none !important; }
</style>
<div class="activity-timeline" style="display:flex;flex-direction:column;gap:0">
    @foreach($items as $item)
        @php
            try {
                $at = filled($item['at'] ?? null)
                    ? \Illuminate\Support\Carbon::parse($item['at'])->timezone('Europe/London')->format('d M · H:i')
                    : null;
            } catch (\Throwable) {
                $at = null;
            }
            $sourceLabel = match ($item['source'] ?? '') {
                'support' => 'Support',
                'security' => 'Security',
                'backup' => 'Backup',
                default => 'Update',
            };
            $href = filled($item['href'] ?? null) ? $item['href'] : null;
            $detailUrl = filled($item['detail_url'] ?? null) ? $item['detail_url'] : null;
            $actions = is_array($item['actions'] ?? null) ? $item['actions'] : [];
            $body = filled($item['body'] ?? null) ? $item['body'] : null;
            $canOpen = $detailUrl || $href || $body || $actions !== [];
        @endphp
        <div
            style="display:grid;grid-template-columns:18px minmax(0,1fr);gap:12px"
            @if($canOpen)
                x-data="{
                    open: false,
                    loading: false,
                    loaded: false,
                    error: false,
                    rows: [],
                    toggle() {
                        this.open = !this.open;
                        if (!this.open || this.loaded || !this.$el.dataset.detailUrl) {
                            return;
                        }
                        this.loading = true;
                        fetch(this.$el.dataset.detailUrl, {
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
                                this.rows = Array.isArray(data.actions) ? data.actions : [];
                                this.loaded = true;
                            })
                            .catch(() => {
                                this.error = true;
                                this.loaded = true;
                            })
                            .finally(() => {
                                this.loading = false;
                            });
                    }
                }"
                @if($detailUrl) data-detail-url="{{ $detailUrl }}" @endif
            @endif
        >
            <div style="position:relative">
                <span style="position:absolute;left:4px;top:6px;width:10px;height:10px;border-radius:999px;background:#FF7000;box-shadow:0 0 0 3px {{ $variant === 'report' ? '#fff4eb' : '#071f2e' }}"></span>
                @if(! $loop->last)
                    <span style="position:absolute;left:8px;top:18px;bottom:-8px;width:2px;background:{{ $lineColor }}"></span>
                @endif
            </div>
            <div style="padding:0 0 18px">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 12px;align-items:baseline">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase" class="{{ $mutedClass }}">
                        {{ $sourceLabel }}
                        @if(filled($item['ref'] ?? null))
                            · {{ $item['ref'] }}
                        @endif
                    </div>
                    @if($at)
                        <div style="font-size:11px" class="{{ $mutedClass }}">{{ $at }}</div>
                    @endif
                </div>
                <div style="margin-top:4px;font-size:14px;font-weight:600;line-height:1.3;color:{{ $titleColor }}">
                    {{ $item['title'] ?? $sourceLabel }}
                </div>
                @if(filled($item['text'] ?? null))
                    <div style="margin-top:4px;font-size:13px;line-height:1.4;color:{{ $titleColor }}">
                        {{ $item['text'] }}
                    </div>
                @endif
                @if(filled($item['badge'] ?? null))
                    <span class="{{ $badgeClass }}">{{ $item['badge'] }}</span>
                @endif
                @if($canOpen)
                    <button type="button" @click="toggle()" :aria-expanded="open.toString()" style="margin-top:8px;padding:0;border:0;background:none;color:#FF7000;font-size:12px;font-weight:700;cursor:pointer">
                        <span x-text="open ? 'Hide detail' : 'Show detail'"></span>
                    </button>
                    <div x-show="open" x-cloak style="margin-top:10px;padding:12px;background:{{ $panelBg }};border:1px solid {{ $panelBorder }}">
                        @if($body)
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.45;color:{{ $titleColor }}">{{ $body }}</p>
                        @endif
                        @if($actions !== [])
                            <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px">
                                @foreach($actions as $action)
                                    <li style="font-size:13px;line-height:1.4;color:{{ $titleColor }}">
                                        {{ $action['title'] ?? 'Update' }}
                                        @if(filled($action['badge'] ?? null))
                                            <span class="{{ $badgeClass }}">{{ $action['badge'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <p x-show="loading" x-cloak style="margin:0;font-size:13px" class="{{ $mutedClass }}">Loading this action...</p>
                        <p x-show="error" x-cloak style="margin:0;font-size:13px" class="{{ $mutedClass }}">We could not load the detail just now.</p>
                        <ul x-show="rows.length" x-cloak style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px">
                            <template x-for="(row, index) in rows" :key="index">
                                <li>
                                    <div style="font-size:13px;font-weight:700;color:{{ $titleColor }}">
                                        <span x-text="row.title"></span>
                                        <span x-show="row.at" class="{{ $mutedClass }}" style="font-weight:500" x-text="row.at ? ' · ' + row.at : ''"></span>
                                    </div>
                                    <p style="margin:2px 0 0;font-size:13px;line-height:1.45;color:{{ $titleColor }}" x-text="row.text"></p>
                                </li>
                            </template>
                        </ul>
                        @if($href)
                            <a href="{{ $href }}" style="display:inline-block;margin-top:10px;color:#FF7000;font-size:12px;font-weight:700">Open the full record</a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
