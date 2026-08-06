@php
    $products = app(\App\Services\Portal\ClientProductService::class);
    $services = $products->serviceMatrixForClient($client);
    $vendors = $products->licenceVendorMatrixForClient($client);
    $chipClass = function (string $colour): string {
        return match ($colour) {
            'green' => 'bg-emerald-500/25 text-emerald-300 border-emerald-400/50',
            'amber' => 'bg-amber-500/25 text-amber-300 border-amber-400/50',
            'red' => 'bg-red-500/25 text-red-300 border-red-400/50',
            default => 'bg-white/[0.04] text-white/35 border-white/10',
        };
    };
@endphp
<div class="flex flex-col gap-1.5 min-w-[7.5rem]">
    <div class="flex flex-wrap gap-1" title="Portal products: S SuperOps · M M365 · H Huntress · D Dropsuite">
        @foreach($services as $cell)
            <span
                class="inline-flex h-6 min-w-[1.5rem] items-center justify-center border text-[10px] font-condensed font-semibold {{ $chipClass($cell['colour']) }}"
                title="{{ $cell['label'] }}: {{ $cell['status_label'] }}"
            >{{ $cell['short'] }}</span>
        @endforeach
    </div>
    @if($vendors !== [])
        <div class="flex flex-wrap items-center gap-1" title="Licence vendor (Pax8 = licences via Pax8; more vendors later)">
            <span class="text-[9px] uppercase tracking-wide text-white/30 mr-0.5">Lic</span>
            @foreach($vendors as $cell)
                <span
                    class="inline-flex h-6 min-w-[1.5rem] items-center justify-center border text-[10px] font-condensed font-semibold {{ $chipClass($cell['colour']) }}"
                    title="{{ $cell['label'] }} (licence vendor): {{ $cell['status_label'] }}"
                >{{ $cell['short'] }}</span>
            @endforeach
        </div>
    @endif
</div>
