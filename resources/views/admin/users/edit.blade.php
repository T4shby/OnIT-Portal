<x-admin-layout>
    <div class="mb-6">
        @if($user->client)
            <a href="{{ route('admin.clients.users.index', $user->client) }}" class="portal-body-muted text-sm hover:text-onit">&larr; {{ $user->client->name }}</a>
        @else
            <a href="{{ route('admin.users.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Users</a>
        @endif
    </div>

    @include('admin.partials.header', ['title' => 'Edit user'])

    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.users.update', $user) }}">
            @csrf @method('PUT')
            <input type="hidden" name="client_id" value="{{ $user->client_id }}">
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $user->name])
            @include('admin.partials.form-field', ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'required' => true, 'value' => $user->email])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Company</label>
                <p class="text-sm text-slate-600">{{ $user->client?->name ?? 'No company (client deleted)' }}</p>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
                <select name="role" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role', $user->role->value) === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $user->is_active])
            <div class="admin-form-actions">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Update</button>
                <a href="{{ $user->client ? route('admin.clients.users.index', $user->client) : route('admin.users.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
