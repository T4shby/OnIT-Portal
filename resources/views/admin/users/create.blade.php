<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add User'])

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true])
            @include('admin.partials.form-field', ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'required' => true])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
                <select name="role" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select name="client_id" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    <option value="">None</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected(old('client_id', $selectedClientId ?? null) == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Assigned Clients (Account Manager)</label>
                @foreach($clients as $client)
                    <label class="flex items-center gap-2 mb-1">
                        <input type="checkbox" name="assigned_clients[]" value="{{ $client->id }}" class="rounded border-slate-300 text-onit">
                        <span class="text-sm">{{ $client->name }}</span>
                    </label>
                @endforeach
            </div>
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => true])
            <div class="flex gap-3 mt-6">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Create</button>
                <a href="{{ route('admin.users.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
