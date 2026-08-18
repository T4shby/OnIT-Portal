<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Client - '.$client->name])

    @if($errors->any())
        <x-alert type="error" class="mb-6">
            <p class="font-semibold">Could not complete - fix the following:</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="admin-split">
        <x-card>
            <form method="POST" action="{{ route('admin.clients.update', $client) }}">
                @csrf @method('PUT')
                @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $client->name])
                @include('admin.clients._products', [
                    'client' => $client,
                    'fieldHelps' => $fieldHelps,
                    'showEntra' => true,
                ])
                @include('admin.clients._client-home-composition', [
                    'client' => $client,
                    'clientHomeComposition' => $clientHomeComposition ?? null,
                ])
                @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $client->is_active])
                @include('admin.clients._entra-sync-fields', ['client' => $client])
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="submit" class="cta-btn text-sm px-6 py-3">Save client</button>
                    <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
                    <a href="{{ route('admin.integration-health.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Integration Health</a>
                </div>
                <p class="portal-body-muted mt-3 text-xs leading-relaxed">
                    Saves product entitlements, mappings, and Entra fields. Checklist ticks are on the right and save when you tick them.
                </p>
            </form>

            <div class="mt-6 space-y-3">
                <p class="portal-label">Client tools</p>
                <div class="flex flex-wrap gap-3">
                    @if($client->isProductEntitled('m365') && $client->entra_tenant_id && config('services.entra_sync.client_id'))
                        <a href="{{ route('admin.clients.microsoft-365', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                            View Microsoft 365 directory
                        </a>
                    @endif
                    @if($client->isProductEntitled('huntress') && filled($client->huntress_organization_id) && config('services.huntress.enabled'))
                        <a href="{{ route('admin.clients.security.huntress', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                            View Huntress security
                        </a>
                    @elseif($client->isProductEntitled('huntress') && config('services.huntress.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Huntress is sold - paste Organization ID above and Save to open the security dashboard.
                        </p>
                    @endif
                    @if($client->isProductEntitled('dropsuite') && filled($client->dropsuite_organization_id) && config('services.dropsuite.enabled'))
                        <a href="{{ route('admin.clients.dropsuite', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                            View Dropsuite backups
                        </a>
                    @elseif($client->isProductEntitled('dropsuite') && config('services.dropsuite.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Dropsuite is sold - paste Organization ID above and Save to open backup metrics.
                        </p>
                    @elseif($client->isProductEntitled('dropsuite') && ! config('services.dropsuite.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Dropsuite is sold for this client but <code class="text-white/70">DROPSUITE_ENABLED</code> is off on the server.
                        </p>
                    @endif
                </div>
            </div>

            @include('admin.clients._entra-sync-actions', ['client' => $client])
        </x-card>

        @include('admin.clients._onboarding-panel', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'onboardingProgress' => $onboardingProgress,
            'adminConsentUrl' => $adminConsentUrl,
        ])
    </div>
</x-admin-layout>
