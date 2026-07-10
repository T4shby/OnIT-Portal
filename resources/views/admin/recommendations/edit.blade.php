<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Recommendation'])
    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.recommendations.update', $recommendation) }}">
            @csrf @method('PUT')
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select name="client_id" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected($recommendation->client_id == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true, 'value' => $recommendation->title])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true, 'value' => $recommendation->body])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                <select name="category" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach(['security','service','technology'] as $cat)
                        <option value="{{ $cat }}" @selected($recommendation->category === $cat)>{{ ucfirst($cat) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Priority</label>
                <select name="priority" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach(['low','medium','high','critical'] as $p)
                        <option value="{{ $p }}" @selected($recommendation->priority === $p)>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Display Order', 'name' => 'display_order', 'type' => 'number', 'value' => $recommendation->display_order])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $recommendation->is_active])
            <div class="admin-form-actions">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Update</button>
                <a href="{{ route('admin.recommendations.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
