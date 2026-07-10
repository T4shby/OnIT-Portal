<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Edit Portal Link'])
    <x-card class="w-full">
        <form method="POST" action="{{ route('admin.portal-links.update', $portalLink) }}">
            @csrf @method('PUT')
            @include('admin.portal-links._form-fields', ['portalLink' => $portalLink])
            <div class="admin-form-actions">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Update</button>
                <a href="{{ route('admin.portal-links.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
