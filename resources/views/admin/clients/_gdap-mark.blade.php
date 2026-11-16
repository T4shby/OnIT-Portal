{{-- Green tick = done. Red cross = not set up. Yellow ? = cannot be checked. Snapshot only. --}}
@php
    $gdap = app(\App\Services\Gdap\GdapSnapshotPresenter::class)->listMark($client);
    $markClass = match ($gdap['mark']) {
        'done' => 'product-chip--live',
        'missing' => 'product-chip--error',
        default => 'product-chip--setup',
    };
    $markLabel = match ($gdap['mark']) {
        'done' => 'GDAP done',
        'missing' => 'GDAP not set up',
        default => 'GDAP cannot be checked',
    };
@endphp
<span class="product-chip {{ $markClass }}" title="{{ $gdap['title'] }}" aria-label="{{ $markLabel }}">
    @if($gdap['mark'] === 'done')
        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5 6.5 11.5 12.5 4.5"/>
        </svg>
    @elseif($gdap['mark'] === 'missing')
        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" d="M4 4l8 8M12 4l-8 8"/>
        </svg>
    @else
        ?
    @endif
</span>
