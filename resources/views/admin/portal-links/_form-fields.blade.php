@php
    $link = $portalLink ?? null;
    $selectedType = old('link_type', $link?->link_type?->value ?? 'external');
@endphp
<div x-data="{ linkType: '{{ $selectedType }}' }" class="admin-form-grid">
    @include('admin.partials.form-field', ['label' => 'Name', 'name' => 'name', 'required' => true, 'value' => $link?->name])
    @include('admin.partials.form-field', ['label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'value' => $link?->description])
    <div class="mb-4 admin-form-span-full">
        <label for="link_type" class="block text-sm font-medium text-slate-700 mb-1">Link type</label>
        <select name="link_type" id="link_type" x-model="linkType" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
            @foreach($linkTypes as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    <div x-show="linkType === 'external'" x-cloak class="admin-form-span-full">
        @include('admin.partials.form-field', ['label' => 'URL', 'name' => 'url', 'type' => 'url', 'required' => true, 'value' => $link?->url])
    </div>
    @include('admin.partials.form-field', ['label' => 'Icon', 'name' => 'icon', 'value' => $link?->icon ?? 'link'])
    <div class="mb-4">
        <label class="block text-sm font-medium text-slate-700 mb-1">Client (empty = global)</label>
        <select name="client_id" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
            <option value="">Global</option>
            @foreach($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $link?->client_id) == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-4">
        <label class="block text-sm font-medium text-slate-700 mb-1">Required role (empty = all)</label>
        <select name="required_role" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-onit focus:ring-onit">
            <option value="">All roles</option>
            @foreach($roles as $role)
                <option value="{{ $role->value }}" @selected(old('required_role', $link?->required_role) === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
    </div>
    @include('admin.partials.form-field', ['label' => 'Display Order', 'name' => 'display_order', 'type' => 'number', 'value' => $link?->display_order ?? 0])
    @include('admin.partials.form-field', ['label' => 'Active', 'name' => 'is_active', 'type' => 'checkbox', 'value' => $link?->is_active ?? true])
    @include('admin.partials.form-field', ['label' => 'Open in new tab', 'name' => 'open_in_new_tab', 'type' => 'checkbox', 'value' => $link?->open_in_new_tab ?? true])
</div>
