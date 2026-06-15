<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Opportunity'])
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.opportunities.store') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select name="client_id" required class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                <select name="category" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    <option value="backup">Backup</option>
                    <option value="security">Security</option>
                    <option value="device_refresh">Device Refresh</option>
                    <option value="new_service">New Service</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                <select name="status" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                    <option value="deferred">Deferred</option>
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Display Order', 'name' => 'display_order', 'type' => 'number', 'value' => 0])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Create</button>
                <a href="{{ route('admin.opportunities.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
