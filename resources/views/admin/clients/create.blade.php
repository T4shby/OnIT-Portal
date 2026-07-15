<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Client'])

    @include('admin.clients._create-client-intro')

    <x-card class="w-full">
        <form method="POST" action="{{ route('admin.clients.store') }}" class="admin-form-grid">
            @csrf
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'fullWidth' => true])
            @include('admin.partials.form-field', [
                'label' => 'SuperOps Account ID',
                'name' => 'superops_account_id',
                'help' => $fieldHelps['superops_account_id'],
            ])
            @include('admin.partials.form-field', [
                'label' => 'Pax8 Company ID',
                'name' => 'pax8_company_id',
                'help' => $fieldHelps['pax8_company_id'],
            ])
            @include('admin.partials.form-field', [
                'label' => 'Dropsuite Organization ID',
                'name' => 'dropsuite_organization_id',
                'help' => $fieldHelps['dropsuite_organization_id'],
            ])
            @include('admin.partials.form-field', [
                'label' => 'Huntress Organization ID',
                'name' => 'huntress_organization_id',
                'help' => $fieldHelps['huntress_organization_id'],
            ])
            @include('admin.partials.form-field', ['label' => 'SuperOps SSO enabled', 'name' => 'superops_sso_enabled', 'type' => 'checkbox', 'value' => true])
            @include('admin.partials.form-field', ['label' => 'Pax8 access enabled', 'name' => 'pax8_sso_enabled', 'type' => 'checkbox', 'value' => false])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="admin-form-actions">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Create client</button>
                <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
