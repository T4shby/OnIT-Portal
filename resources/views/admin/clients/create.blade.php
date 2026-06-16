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
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Create</button>
                <a href="{{ route('admin.clients.index') }}" class="px-4 py-2 text-slate-600 hover:text-slate-900 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
