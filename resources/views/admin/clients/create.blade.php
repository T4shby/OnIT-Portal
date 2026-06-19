<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Client'])

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.clients.store') }}">
            @csrf
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'SuperOps Account ID', 'name' => 'superops_account_id'])
            @include('admin.partials.form-field', ['label' => 'SuperOps SSO enabled', 'name' => 'superops_sso_enabled', 'type' => 'checkbox', 'value' => true])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            @include('admin.clients._entra-sync-fields', ['client' => new \App\Models\Client()])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Create</button>
                <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
        <p class="portal-body-muted mt-6 text-xs border-t border-white/10 pt-4">After creating the client, open <strong class="text-white/80">Edit</strong> to see the full setup checklist with M365 consent link and step-by-step instructions.</p>
    </x-card>
</x-admin-layout>
