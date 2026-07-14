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
                @include('admin.partials.form-field', ['label' => 'Pax8 access enabled', 'name' => 'pax8_sso_enabled', 'type' => 'checkbox', 'value' => $client->pax8_sso_enabled])
                @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $client->is_active])
                @include('admin.clients._entra-sync-fields', ['client' => $client])
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="submit" class="cta-btn text-sm px-6 py-3">Save client</button>
                    <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
                </div>
            </form>

            @if($client->entra_tenant_id && config('services.entra_sync.client_id'))
                <div class="mt-4">
                    <a href="{{ route('admin.clients.microsoft-365', $client) }}" class="cta-btn-ghost text-sm px-6 py-3 inline-block">
                        View Microsoft 365 directory
                    </a>
                </div>
            @endif

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
