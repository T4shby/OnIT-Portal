@php
    $products = app(\App\Services\Portal\ClientProductService::class);
    $matrix = $products->matrixForClient($client);
@endphp
<div class="flex flex-wrap gap-1" title="S SuperOps · M M365 · H Huntress · D Dropsuite · P Pax8">
    @foreach($matrix as $cell)
        @php
            $chip = match ($cell['colour']) {
                'green' => 'bg-emerald-500/20 text-emerald-300 border-emerald-400/40',
                'amber' => 'bg-amber-500/20 text-amber-300 border-amber-400/40',
                'red' => 'bg-red-500/20 text-red-300 border-red-400/40',
                default => 'bg-white/5 text-white/40 border-white/15',
            };
        @endphp
        <span
            class="inline-flex h-6 min-w-[1.5rem] items-center justify-center border text-[10px] font-condensed font-semibold {{ $chip }}"
            title="{{ $cell['label'] }}: {{ $cell['status_label'] }}"
        >{{ $cell['short'] }}</span>
    @endforeach
</div>
