<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Client'])

    <div class="max-w-2xl">
        <p class="portal-body-muted mb-6 max-w-prose text-sm leading-relaxed">
            Enter the client details below and click <strong class="text-white/80">Create</strong>.
            You land on <strong class="text-white/80">Edit Client</strong> next — the <strong class="text-white/80">Client setup guide</strong> on the right starts at step 01 (Link SuperOps), not here.
        </p>

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
                @include('admin.partials.form-field', ['label' => 'Pax8 access enabled', 'name' => 'pax8_sso_enabled', 'type' => 'checkbox', 'value' => false])
                @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
                @include('admin.clients._entra-sync-fields', ['client' => $client])
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="cta-btn text-sm px-6 py-3">Create</button>
                    <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-admin-layout>
