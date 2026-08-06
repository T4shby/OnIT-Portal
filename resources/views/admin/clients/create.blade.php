<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Client'])

    @include('admin.clients._create-client-intro')

    <x-card class="w-full">
        <form method="POST" action="{{ route('admin.clients.store') }}" class="space-y-6">
            @csrf
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true])
            @include('admin.clients._products', [
                'client' => $client,
                'fieldHelps' => $fieldHelps,
                'showEntra' => false,
            ])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Create client</button>
                <a href="{{ route('admin.clients.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
