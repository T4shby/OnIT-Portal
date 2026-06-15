<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Opportunity'])
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.opportunities.update', $opportunity) }}">
            @csrf @method('PUT')
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select name="client_id" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected($opportunity->client_id == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true, 'value' => $opportunity->title])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true, 'value' => $opportunity->body])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                <select name="category" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach(['backup','security','device_refresh','new_service'] as $cat)
                        <option value="{{ $cat }}" @selected($opportunity->category === $cat)>{{ str_replace('_', ' ', ucfirst($cat)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                <select name="status" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach(['open','in_progress','won','lost','deferred'] as $s)
                        <option value="{{ $s }}" @selected($opportunity->status === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Display Order', 'name' => 'display_order', 'type' => 'number', 'value' => $opportunity->display_order])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $opportunity->is_active])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Update</button>
                <a href="{{ route('admin.opportunities.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
