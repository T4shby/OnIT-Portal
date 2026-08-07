{{-- Fixed-size chips: green live · yellow setup · red error · grey not sold. Vendor chips same size, dashed. --}}
@php
    $products = app(\App\Services\Portal\ClientProductService::class);
    $services = $products->serviceMatrixForClient($client);
    $vendors = $products->licenceVendorMatrixForClient($client);
    $chipState = function (string $colour): string {
        return match ($colour) {
            'green' => 'product-chip--live',
            'amber' => 'product-chip--setup',
            'red' => 'product-chip--error',
            default => 'product-chip--muted',
        };
    };
@endphp
<div class="product-chip-row">
    @foreach($services as $cell)
        <span
            class="product-chip {{ $chipState($cell['colour']) }}"
            title="{{ $cell['label'] }}: {{ $cell['status_label'] }}"
        >{{ $cell['short'] }}</span>
    @endforeach

    @if($vendors !== [])
        <span class="product-chip-sep" aria-hidden="true" title="Licence vendor">·</span>
        @foreach($vendors as $cell)
            <span
                class="product-chip product-chip--vendor {{ $chipState($cell['colour']) }}"
                title="{{ $cell['label'] }} (licence vendor): {{ $cell['status_label'] }}"
            >{{ $cell['short'] }}</span>
        @endforeach
    @endif
</div>
