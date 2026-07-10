<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Settings'])

    <x-card class="w-full">
        <form method="POST" class="admin-form-grid" action="{{ route('admin.settings.update') }}">
            @csrf @method('PUT')
            @foreach($settings as $index => $setting)
                <input type="hidden" name="settings[{{ $index }}][key]" value="{{ $setting->key }}">
                @include('admin.partials.form-field', [
                    'label' => str_replace('_', ' ', ucfirst($setting->key)),
                    'name' => "settings[{$index}][value]",
                    'value' => $setting->value,
                ])
            @endforeach
            <div class="admin-form-actions">
                <button type="submit" class="cta-btn text-sm px-6 py-3">Save Settings</button>
            </div>
        </form>
    </x-card>
</x-admin-layout>
