<x-app-layout>
  <div class="mb-6"><a href="{{ route('support.index') }}" class="text-sm text-onit font-medium">← Back</a></div>
  <x-card class="max-w-2xl">
    <h1 class="text-xl font-bold mb-6">New support request</h1>
    <form method="POST" action="{{ route('support.store') }}" class="space-y-5">
      @csrf
      @include('admin.partials.form-field', ['label' => 'Subject', 'name' => 'subject', 'required' => true, 'value' => old('subject')])
      @include('admin.partials.form-field', ['label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'required' => true, 'value' => old('description')])
      <button type="submit" class="px-4 py-2 bg-onit text-white rounded-lg text-sm font-medium">Submit</button>
    </form>
  </x-card>
</x-app-layout>
