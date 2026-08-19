@php
    $names = array_values($names ?? []);
    $perPage = max(1, (int) ($perPage ?? 5));
    $empty = $empty ?? null;
    $chunks = $names === [] ? [] : array_chunk($names, $perPage);
    $pageCount = count($chunks);
@endphp
@if($names === [])
    @if($empty)
        <p class="org-muted" style="margin:8px 0 0;font-size:12px">{{ $empty }}</p>
    @endif
@else
    <div class="org-pager" x-data="{ page: 1, pages: {{ $pageCount }} }">
        @foreach($chunks as $index => $chunk)
            <div @if($index > 0) x-cloak @endif x-show="page === {{ $index + 1 }}">
                @foreach($chunk as $name)
                    <p class="org-muted org-pager__name">{{ $name }}</p>
                @endforeach
            </div>
        @endforeach
        @if($pageCount > 1)
            <div class="org-pager__nav">
                <button type="button" class="org-range-btn" @click="if (page > 1) page--" :disabled="page === 1">Prev</button>
                <span class="org-muted" style="font-size:11px" x-text="'Page ' + page + ' of ' + pages">Page 1 of {{ $pageCount }}</span>
                <button type="button" class="org-range-btn" @click="if (page < pages) page++" :disabled="page === pages">Next</button>
            </div>
        @endif
    </div>
@endif
