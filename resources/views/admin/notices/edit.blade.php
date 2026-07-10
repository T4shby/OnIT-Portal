<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Notice'])
    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.notices.update', $notice) }}">
            @csrf @method('PUT')
            <div class="mb-4">
                <label for="client_id" class="portal-label mb-2 block">Client</label>
                <select name="client_id" id="client_id" class="admin-input">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected($notice->client_id == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Title', 'name' => 'title', 'required' => true, 'value' => $notice->title])
            @include('admin.partials.form-field', ['label' => 'Body', 'name' => 'body', 'type' => 'textarea', 'required' => true, 'value' => $notice->body])
            @include('admin.partials.form-field', [
                'label' => 'Published At',
                'name' => 'published_at',
                'type' => 'datetime-local',
                'value' => $notice->published_at?->format('Y-m-d\TH:i'),
            ])
            @include('admin.partials.form-field', [
                'label' => 'Expires At',
                'name' => 'expires_at',
                'type' => 'datetime-local',
                'value' => $notice->expires_at?->format('Y-m-d\TH:i'),
            ])
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $notice->is_active])
            <div class="admin-form-actions">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Update</button>
                <a href="{{ route('admin.notices.index') }}" class="cta-btn-ghost text-sm px-6 py-3">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
