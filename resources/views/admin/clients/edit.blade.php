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

    {{-- Top: customer tools + DNS checker (full width). Then identity form + setup guide. --}}
    <div class="admin-edit-stack">
        <x-card class="admin-edit-tools overflow-hidden">
            <div class="mb-5">
                <h3 class="admin-section-title mb-1 text-base">Customer tools and checkers</h3>
                <p class="text-xs text-white/50 max-w-3xl">
                    Open live views and run DNS health before editing mappings below.
                    Mail DNS is MXToolbox-style - core = MX / SPF / DKIM / DMARC.
                </p>
            </div>

            <div class="mb-6 space-y-3">
                <p class="portal-label">Tools</p>
                <div class="flex flex-wrap gap-3">
                    @if($client->isProductEntitled('m365') && $client->entra_tenant_id && config('services.entra_sync.client_id'))
                        <a href="{{ route('admin.clients.microsoft-365', $client) }}" class="cta-btn-ghost text-sm px-5 py-2.5 inline-block">
                            View Microsoft 365 directory
                        </a>
                        <a href="{{ route('admin.clients.mfa-methods', $client) }}" class="cta-btn-ghost text-sm px-5 py-2.5 inline-block">
                            MFA methods / passkey readiness
                        </a>
                    @elseif(filled($client->entra_tenant_id) && config('services.entra_sync.client_id'))
                        <a href="{{ route('admin.clients.mfa-methods', $client) }}" class="cta-btn-ghost text-sm px-5 py-2.5 inline-block">
                            MFA methods / passkey readiness
                        </a>
                    @endif
                    @if($client->isProductEntitled('huntress') && filled($client->huntress_organization_id) && config('services.huntress.enabled'))
                        <a href="{{ route('admin.clients.security.huntress', $client) }}" class="cta-btn-ghost text-sm px-5 py-2.5 inline-block">
                            View Huntress security
                        </a>
                    @elseif($client->isProductEntitled('huntress') && config('services.huntress.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Huntress is sold - paste Organization ID in Identity below and Save.
                        </p>
                    @endif
                    @if($client->isProductEntitled('dropsuite') && filled($client->dropsuite_organization_id) && config('services.dropsuite.enabled'))
                        <a href="{{ route('admin.clients.dropsuite', $client) }}" class="cta-btn-ghost text-sm px-5 py-2.5 inline-block">
                            View Dropsuite backups
                        </a>
                    @elseif($client->isProductEntitled('dropsuite') && config('services.dropsuite.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Dropsuite is sold - paste Organization ID in Identity below and Save.
                        </p>
                    @elseif($client->isProductEntitled('dropsuite') && ! config('services.dropsuite.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Dropsuite is sold for this client but <code class="text-white/70">DROPSUITE_ENABLED</code> is off on the server.
                        </p>
                    @endif
                </div>

                @include('admin.clients._entra-sync-actions', ['client' => $client])
            </div>

            <div class="border-t border-white/10 pt-5">
                @include('admin.clients._mail-domains', [
                    'client' => $client,
                    'mailDomains' => $mailDomains ?? $client->domains,
                    'mailDnsStillRed' => $mailDnsStillRed ?? false,
                    'entraMailDomains' => $entraMailDomains ?? null,
                    'mailDnsHistory' => $mailDnsHistory ?? collect(),
                ])
            </div>
        </x-card>

        <div class="admin-split">
            <div class="admin-split__main">
                <x-card class="overflow-hidden">
                    <form method="POST" action="{{ route('admin.clients.update', $client) }}" id="client-edit-form">
                        @csrf @method('PUT')

                        <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="admin-section-title mb-1 text-base">Identity and products</h3>
                                <p class="text-xs text-white/50">Name, main domain, sold products, and Entra fields. Save stores the primary DNS row for step 00.</p>
                            </div>
                            <button type="submit" class="cta-btn shrink-0 text-sm px-5 py-2.5">Save client</button>
                        </div>

                        <div class="admin-form-grid mb-2">
                            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $client->name])
                            @include('admin.partials.form-field', [
                                'label' => 'Main domain',
                                'name' => 'primary_domain',
                                'required' => true,
                                'value' => $client->primary_domain,
                                'help' => [
                                    'Primary mail domain (e.g. clientco.co.uk).',
                                    'Save client stores it and runs DNS check.',
                                    'Extra domains / Import live in Customer tools and checkers above.',
                                ],
                            ])
                        </div>

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
                            Saves identity, products, mappings, and Entra IDs. Checklist ticks are in the guide on the right.
                        </p>
                    </form>
                </x-card>
            </div>

            @include('admin.clients._onboarding-panel', [
                'client' => $client,
                'onboardingSteps' => $onboardingSteps,
                'onboardingProgress' => $onboardingProgress,
                'adminConsentUrl' => $adminConsentUrl,
                'gdap' => $gdap ?? ['enabled' => false, 'blocks_connect' => false],
            ])
        </div>
    </div>
</x-admin-layout>
