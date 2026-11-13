@php
    $tone = match ($gdap['status'] ?? '') {
        'ready' => 'border-emerald-400/40 bg-emerald-400/10 text-emerald-50',
        'error', 'missing', 'incomplete' => 'border-rose-400/40 bg-rose-400/10 text-rose-50',
        default => 'border-amber-400/40 bg-amber-400/10 text-amber-50',
    };
@endphp

<div class="mt-3 border px-3 py-2 text-xs leading-relaxed {{ $tone }}">
    <p>
        <strong class="text-white">GDAP</strong>
        <span class="text-white/80">{{ $gdap['status_label'] ?? 'Not checked' }}</span>
        @if(! empty($gdap['end_label']))
            <span class="text-white/60">· ends {{ $gdap['end_label'] }}</span>
        @endif
    </p>
    @if(filled($gdap['summary'] ?? null))
        <p class="mt-1 text-white/80">{{ $gdap['summary'] }}</p>
    @endif
    @if(! empty($gdap['customer_tenant_id']))
        <p class="mt-1 text-white/50">Tenant {{ $gdap['customer_tenant_id'] }}</p>
    @endif
    @if(! empty($gdap['approval_url']))
        <p class="mt-2">
            <a
                href="{{ $gdap['approval_url'] }}"
                target="_blank"
                rel="noopener"
                class="text-white underline"
            >Open GDAP approval</a>
            <span class="text-white/60">A customer Global Admin must approve. The portal cannot approve its own request.</span>
        </p>
    @endif
    <form method="POST" action="{{ route('admin.clients.gdap.refresh', $client) }}" class="mt-2">
        @csrf
        <button type="submit" class="cta-btn-ghost px-3 py-1.5 text-xs">Check GDAP now</button>
    </form>
</div>
