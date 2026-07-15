<x-admin-layout>
    <div class="mb-6">
        <a href="{{ route('admin.clients.users.index', $client) }}" class="portal-body-muted text-sm hover:text-onit">&larr; {{ $client->name }}</a>
    </div>

    @include('admin.partials.header', ['title' => 'Add user — '.$client->name])

    <x-card class="w-full">
        <p class="portal-body-muted mb-6 text-sm">
            Add a portal user for <strong class="text-white/80">{{ $client->name }}</strong>.
            @if(auth()->user()->role === \App\Enums\UserRole::SuperAdmin)
                On IT team members are managed separately under <a href="{{ route('admin.team.index') }}" class="hover:text-onit">Team</a>.
            @endif
        </p>

        <form method="POST" class="admin-form-grid" action="{{ route('admin.users.store') }}">
            @csrf
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'required' => true])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
                <select name="role" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role', \App\Enums\UserRole::ClientRequester->value) === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="admin-form-actions">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Create user</button>
                <a href="{{ route('admin.clients.users.index', $client) }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
