@php
    $names = array_values($names ?? []);
    $perPage = (int) ($perPage ?? 5);
    $empty = $empty ?? null;
@endphp
@if($names === [])
    @if($empty)
        <p class="org-muted" style="margin:8px 0 0;font-size:12px">{{ $empty }}</p>
    @endif
@else
    <div class="org-pager" x-data="{
        names: {{ Js::from($names) }},
        page: 1,
        per: {{ $perPage }},
        get pages() { return Math.max(1, Math.ceil(this.names.length / this.per)); },
        get slice() { return this.names.slice((this.page - 1) * this.per, this.page * this.per); },
        prev() { if (this.page > 1) this.page--; },
        next() { if (this.page < this.pages) this.page++; },
    }">
        <template x-for="(name, i) in slice" :key="page + '-' + i + '-' + name">
            <p class="org-muted" style="margin:6px 0 0;font-size:12px" x-text="name"></p>
        </template>
        <div class="org-pager__nav" x-show="pages > 1" x-cloak>
            <button type="button" class="org-range-btn" @click="prev()" :disabled="page === 1">Prev</button>
            <span class="org-muted" style="font-size:11px" x-text="'Page ' + page + ' of ' + pages"></span>
            <button type="button" class="org-range-btn" @click="next()" :disabled="page === pages">Next</button>
        </div>
    </div>
@endif
