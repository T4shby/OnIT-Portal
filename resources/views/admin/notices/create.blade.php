<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Notice'])
    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.notices.store') }}">
            @csrf
            <div class="mb-4">
                <label for="client_id" class="portal-label mb-2 block">Client <span class="text-onit">*</span></label>
                <select name="client_id" id="client_id" required class="admin-input">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'Published At', 'name' => 'published_at', 'type' => 'datetime-local'])
            @include('admin.partials.form-field', ['label' => 'Expires At', 'name' => 'expires_at', 'type' => 'datetime-local'])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="admin-form-actions">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Create</button>
                <a href="{{ route('admin.notices.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
