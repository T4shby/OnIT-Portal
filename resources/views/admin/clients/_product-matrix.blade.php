{{-- Single nowrap chip row: services · separator · licence vendors (modular vendors append after |). --}}
@php
    $products = app(\App\Services\Portal\ClientProductService::class);
    $services = $products->serviceMatrixForClient($client);
    $vendors = $products->licenceVendorMatrixForClient($client);
    $chipClass = function (string $colour): string {
        return match ($colour) {
            'green' => 'border-emerald-400/60 bg-emerald-500/20 !text-emerald-300',
            'amber' => 'border-amber-400/60 bg-amber-500/20 !text-amber-300',
            'red' => 'border-red-400/60 bg-red-500/20 !text-red-300',
            default => 'border-white/15 bg-white/[0.04] !text-white/40',
        };
    };
@endphp
<div class="inline-flex max-w-none flex-nowrap items-center gap-1 whitespace-nowrap leading-none">
    @foreach($services as $cell)
        <span
            class="inline-flex h-6 w-6 shrink-0 items-center justify-center border text-[11px] font-condensed font-semibold leading-none {{ $chipClass($cell['colour']) }}"
            title="{{ $cell['label'] }}: {{ $cell['status_label'] }}"
        >{{ $cell['short'] }}</span>
    @endforeach

    @if($vendors !== [])
        <span class="mx-0.5 shrink-0 select-none text-[10px] font-light text-white/25" aria-hidden="true" title="Licence vendor">·</span>
        @foreach($vendors as $cell)
            <span
                class="inline-flex h-6 min-w-[1.5rem] shrink-0 items-center justify-center border border-dashed px-1 text-[11px] font-condensed font-semibold leading-none {{ $chipClass($cell['colour']) }}"
                title="{{ $cell['label'] }} (licence vendor): {{ $cell['status_label'] }}"
            >{{ $cell['short'] }}</span>
        @endforeach
    @endif
</div>
