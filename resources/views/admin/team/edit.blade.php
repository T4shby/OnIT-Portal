<x-admin-layout>
    <div class="mb-6">
        <a href="{{ route('admin.team.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Team</a>
    </div>

    @include('admin.partials.header', ['title' => 'Edit team member'])

    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.team.update', $user) }}">
            @csrf @method('PUT')
            @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $user->name])
            @include('admin.partials.form-field', ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'required' => true, 'value' => $user->email])
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
                <select name="role" id="team-role" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role', $user->role->value) === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4 admin-form-span-full" id="assigned-clients-field">
                <label class="block text-sm font-medium text-slate-700 mb-1">Assigned clients</label>
                <p class="mb-2 text-xs text-slate-500">Select which client companies this account manager can manage.</p>
                @foreach($clients as $client)
                    <label class="flex items-center gap-2 mb-1">
                        <input type="checkbox" name="assigned_clients[]" value="{{ $client->id }}"
                            @checked(in_array($client->id, old('assigned_clients', $assignedClients)))
                            class="rounded border-slate-300 text-onit">
                        <span class="text-sm">{{ $client->name }}</span>
                    </label>
                @endforeach
                @if(! empty($inactiveAssignedClients))
                    <p class="mt-2 text-xs text-slate-500">
                        Also assigned to inactive clients (kept when you save):
                        {{ implode(', ', $inactiveAssignedClients) }}
                    </p>
                @endif
            </div>
            @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $user->is_active])
            <div class="admin-form-actions">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">Update</button>
                <a href="{{ route('admin.team.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>

    <script>
        const roleSelect = document.getElementById('team-role');
        const assignedField = document.getElementById('assigned-clients-field');
        const accountManagerRole = @json(\App\Enums\UserRole::AccountManager->value);

        function toggleAssignedClients() {
            assignedField.style.display = roleSelect.value === accountManagerRole ? 'block' : 'none';
        }

        roleSelect.addEventListener('change', toggleAssignedClients);
        toggleAssignedClients();
    </script>
</x-admin-layout>
