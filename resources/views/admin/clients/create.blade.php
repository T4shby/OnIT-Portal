<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Client'])

    <div class="grid gap-10 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] max-w-7xl">
        <x-card>
            <form method="POST" action="{{ route('admin.clients.store') }}">
                @csrf
                @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true])
                @include('admin.partials.form-field', [
                    'label' => 'SuperOps Account ID',
                    'name' => 'superops_account_id',
                    'help' => $fieldHelps['superops_account_id'],
                ])
                @include('admin.partials.form-field', ['label' => 'SuperOps SSO enabled', 'name' => 'superops_sso_enabled', 'type' => 'checkbox', 'value' => true])
                @include('admin.partials.form-field', [
                    'label' => 'Pax8 Company ID',
                    'name' => 'pax8_company_id',
                    'help' => $fieldHelps['pax8_company_id'],
                ])
                @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
                @include('admin.clients._entra-sync-fields', ['client' => $client])
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="cta-btn text-sm px-6 py-3">Create</button>
                    <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
                </div>
            </form>
        </x-card>

        @include('admin.clients._onboarding-panel', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'onboardingProgress' => $onboardingProgress,
            'adminConsentUrl' => $adminConsentUrl,
        ])
    </div>
</x-admin-layout>
