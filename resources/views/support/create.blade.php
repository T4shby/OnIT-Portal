<x-app-layout title="New support request" content-class="max-w-[96rem]">
  <div class="mb-6"><a href="{{ route('contact-support.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to Contact Support</a></div>
  <x-card class="w-full">
    <h1 class="section-heading-white mb-6 !text-xl">New support request</h1>
    <p class="portal-body-muted text-sm mb-6 leading-relaxed">
      Submit the request here. After it is created, replies show on the ticket. Files stay in the full service desk
      (<a href="{{ route('integrations.superops.launch') }}" class="text-onit hover:text-white">Open full service desk</a>).
    </p>
    <form method="POST" action="{{ route('support.store') }}" class="admin-form-grid max-w-3xl">
      @csrf
      @include('admin.partials.form-field', ['label' => 'Subject', 'name' => 'subject', 'required' => true, 'value' => old('subject'), 'fullWidth' => true])
      @include('admin.partials.form-field', ['label' => 'Description', 'name' => 'description', 'type' => 'textarea', 'required' => true, 'value' => old('description')])
      <div class="admin-form-actions">
        <button type="submit" class="cta-btn text-sm">Submit request</button>
      </div>
    </form>
  </x-card>
</x-app-layout>
