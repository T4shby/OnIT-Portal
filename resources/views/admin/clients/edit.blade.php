<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Client'])

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.clients.update', $client) }}">
            @csrf @method('PUT')
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $client->name])
            @include('admin.partials.form-field', ['label' => 'SuperOps Account ID', 'name' => 'superops_account_id', 'value' => $client->superops_account_id])
            @include('admin.partials.form-field', ['label' => 'SuperOps SSO enabled', 'name' => 'superops_sso_enabled', 'type' => 'checkbox', 'value' => $client->superops_sso_enabled])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $client->is_active])
            @include('admin.clients._entra-sync-fields', ['client' => $client])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Update</button>
                <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
