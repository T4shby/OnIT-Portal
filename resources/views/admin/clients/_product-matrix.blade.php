{{-- Named status pills. $group is services (default) or vendors. --}}
@php
    $products = app(\App\Services\Portal\ClientProductService::class);
    $group = $group ?? 'services';
    $cells = $group === 'vendors'
        ? $products->licenceVendorMatrixForClient($client)
        : $products->serviceMatrixForClient($client);
    $vendor = $group === 'vendors';
    $chipState = function (string $colour): string {
        return match ($colour) {
            'green' => 'product-chip--live',
            'amber' => 'product-chip--setup',
            'red' => 'product-chip--error',
            default => 'product-chip--muted',
        };
    };
@endphp
<div class="product-pill-row">
    @forelse($cells as $cell)
        <span
            class="product-pill {{ $vendor ? 'product-chip--vendor' : '' }} {{ $chipState($cell['colour']) }}"
            title="{{ $cell['label'] }}: {{ $cell['status_label'] }}"
        >{{ $cell['list_label'] }}</span>
    @empty
        <span class="text-white/30">-</span>
    @endforelse
</div>
