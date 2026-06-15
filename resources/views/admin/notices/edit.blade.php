<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Notice'])
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.notices.update', $notice) }}">
            @csrf @method('PUT')
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select name="client_id" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected($notice->client_id == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true, 'value' => $notice->title])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true, 'value' => $notice->body])
            @include('admin.partials.form-field', ['label' => 'Published At', 'name' => 'published_at', 'type' => 'datetime-local', 'value' => $notice->published_at?->format('Y-m-d\TH:i']))
            @include('admin.partials.form-field', ['label' => 'Expires At', 'name' => 'expires_at', 'type' => 'datetime-local', 'value' => $notice->expires_at?->format('Y-m-d\TH:i']))
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $notice->is_active])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Update</button>
                <a href="{{ route('admin.notices.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
