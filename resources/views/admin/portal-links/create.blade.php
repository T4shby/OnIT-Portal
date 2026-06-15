<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Add Portal Link'])
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('admin.portal-links.store') }}">
            @csrf
            @include('admin.portal-links._form-fields')
            <div class="flex gap-3 mt-6">
                <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Create</button>
                <a href="{{ route('admin.portal-links.index') }}" class="px-4 py-2 text-slate-600 text-sm">Cancel</a>
            </div>
        </form>
    </x-card>
</x-admin-layout>
