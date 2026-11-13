@php
    $insights = $m365Insights ?? null;
    $hasMfa = $insights && $insights->mfaRegisteredPct !== null && $insights->mfaUserSample !== null;
    $registered = $hasMfa
        ? (int) round(((float) $insights->mfaRegisteredPct / 100) * (int) $insights->mfaUserSample)
        : null;
    $notRegistered = $hasMfa ? max(0, (int) $insights->mfaUserSample - $registered) : null;
    $mfaUrl = null;
    if ($hasMfa) {
        $mfaUrl = ($adminContext ?? false)
            ? route('admin.clients.mfa-methods', $client)
            : route('microsoft-365.mfa-methods');
    }
    $used = $insights?->sharePointUsedBytes;
    $allocated = $insights?->sharePointAllocatedBytes;
    $formatBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return '-';
        }
        $gb = $bytes / 1073741824;
        if ($gb >= 1024) {
            return number_format($gb / 1024, 2).' TB';
        }

        return number_format($gb, 1).' GB';
    };
@endphp

@if($hasMfa || $used !== null)
    @include('partials.org-theme-styles')
    <section class="org mb-10" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:12px">
        @if($hasMfa)
            <div class="org-card org-card-pad" x-data="{ open: false }">
                <button type="button" @click="open = !open" style="width:100%;text-align:left;background:transparent;border:0;padding:0;color:inherit;cursor:pointer">
                    <p class="org-label" style="margin:0">MFA registration</p>
                    <p class="org-hero-num org-accent" style="margin:8px 0 0">{{ number_format((float) $insights->mfaRegisteredPct, 0) }}%</p>
                    <p class="org-muted" style="margin:4px 0 0;font-size:12px">of {{ number_format((int) $insights->mfaUserSample) }} people · <span x-text="open ? 'Hide' : 'Details'"></span></p>
                </button>
                <div x-show="open" x-cloak style="margin-top:12px">
                    <div class="org-stack" style="margin-top:0">
                        <div class="org-metric">
                            <span class="org-metric-l">Registered</span>
                            <span class="org-metric-v">{{ number_format($registered) }}</span>
                        </div>
                        <div class="org-metric">
                            <span class="org-metric-l">Not registered</span>
                            <span class="org-metric-v">{{ number_format($notRegistered) }}</span>
                        </div>
                    </div>
                    <a href="{{ $mfaUrl }}" class="org-link" style="margin-top:12px">Methods, SMS, and passkeys →</a>
                </div>
            </div>
        @endif

        @if($used !== null)
            <div class="org-card org-card-pad" x-data="{ open: false }">
                <button type="button" @click="open = !open" style="width:100%;text-align:left;background:transparent;border:0;padding:0;color:inherit;cursor:pointer">
                    <p class="org-label" style="margin:0">SharePoint storage</p>
                    <p class="org-hero-num" style="margin:8px 0 0">{{ $formatBytes($used) }}</p>
                    <p class="org-muted" style="margin:4px 0 0;font-size:12px">
                        used
                        @if($allocated)
                            of {{ $formatBytes($allocated) }} allocated
                        @endif
                        · <span x-text="open ? 'Hide' : 'Details'"></span>
                    </p>
                </button>
                <div x-show="open" x-cloak style="margin-top:12px">
                    <p class="org-muted" style="margin:0;font-size:12px;line-height:1.45">
                        Summed from Microsoft's SharePoint site usage report
                        @if($insights->sharePointSiteCount)
                            ({{ number_format($insights->sharePointSiteCount) }} sites)
                        @endif
                        . Allocated is the total of those site quotas. It can differ from the storage pool purchased for the tenant.
                    </p>
                </div>
            </div>
        @endif
    </section>
@endif
