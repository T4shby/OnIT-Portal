<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Client — '.$client->name])

    @if($errors->any())
        <x-alert type="error" class="mb-6">
            <p class="font-semibold">Could not save — fix the following:</p>
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
                @include('admin.partials.form-field', [
                    'label' => 'SuperOps Account ID',
                    'name' => 'superops_account_id',
                    'value' => $client->superops_account_id,
                    'help' => $fieldHelps['superops_account_id'],
                ])
                @include('admin.partials.form-field', ['label' => 'SuperOps SSO enabled', 'name' => 'superops_sso_enabled', 'type' => 'checkbox', 'value' => $client->superops_sso_enabled])
                @include('admin.partials.form-field', [
                    'label' => 'Pax8 Company ID',
                    'name' => 'pax8_company_id',
                    'value' => $client->pax8_company_id,
                    'help' => $fieldHelps['pax8_company_id'],
                ])
                @include('admin.partials.form-field', [
                    'label' => 'Huntress Organization ID',
                    'name' => 'huntress_organization_id',
                    'value' => $client->huntress_organization_id,
                    'help' => $fieldHelps['huntress_organization_id'],
                ])
                @include('admin.partials.form-field', ['label' => 'Pax8 access enabled', 'name' => 'pax8_sso_enabled', 'type' => 'checkbox', 'value' => $client->pax8_sso_enabled])
                @include('admin.partials.form-field', [
                    'label' => 'Dropsuite Organization ID',
                    'name' => 'dropsuite_organization_id',
                    'value' => $client->dropsuite_organization_id,
                    'help' => $fieldHelps['dropsuite_organization_id'],
                ])
                @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $client->is_active])
                @include('admin.clients._entra-sync-fields', ['client' => $client])
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="submit" class="cta-btn text-sm px-6 py-3">Save client</button>
                    <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
                </div>
                <p class="portal-body-muted mt-3 text-xs leading-relaxed">
                    Saves SuperOps / Entra fields on this form only. Checklist ticks are on the right and save when you tick them.
                </p>
            </form>

            <div class="mt-6 space-y-3">
                <p class="portal-label">Client tools</p>
                <div class="flex flex-wrap gap-3">
                    @if($client->entra_tenant_id && config('services.entra_sync.client_id'))
                        <a href="{{ route('admin.clients.microsoft-365', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                            View Microsoft 365 directory
                        </a>
                    @endif
                    @if(filled($client->huntress_organization_id) && config('services.huntress.enabled'))
                        <a href="{{ route('admin.clients.security.huntress', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                            View Huntress security
                        </a>
                    @elseif(config('services.huntress.enabled'))
                        <p class="portal-body-muted text-xs self-center max-w-sm">
                            Set Huntress Organization ID (numeric org id from Huntress URL) to open the security dashboard for this client.
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
