<x-app-layout title="New starter" content-class="max-w-[96rem]">
    <div class="mb-6">
        <a href="{{ route('contact-support.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to Contact Support</a>
    </div>

    <section class="mb-8">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">New</h1>
            <h1 class="section-heading-orange">Starter</h1>
        </div>
        <p class="portal-body-muted max-w-2xl">
            Tell us about the person joining <strong class="text-white/80">{{ $user->client?->name }}</strong>.
            We will create a Service Desk ticket so On IT can set up accounts and access.
        </p>
    </section>

    <x-card class="w-full">
        <form method="POST" action="{{ route('contact-support.new-starter.store') }}" class="admin-form-grid max-w-4xl">
            @csrf
            @include('admin.partials.form-field', [
                'label' => 'Starter full name',
                'name' => 'starter_name',
                'required' => true,
                'value' => old('starter_name'),
                'fullWidth' => true,
            ])
            @include('admin.partials.form-field', [
                'label' => 'Job title',
                'name' => 'job_title',
                'value' => old('job_title'),
            ])
            @include('admin.partials.form-field', [
                'label' => 'Start date',
                'name' => 'start_date',
                'type' => 'date',
                'value' => old('start_date'),
            ])
            @include('admin.partials.form-field', [
                'label' => 'Department / team',
                'name' => 'department',
                'value' => old('department'),
            ])
            @include('admin.partials.form-field', [
                'label' => 'Line manager',
                'name' => 'manager_name',
                'value' => old('manager_name'),
            ])
            @include('admin.partials.form-field', [
                'label' => 'Email to create (if known)',
                'name' => 'starter_email',
                'type' => 'email',
                'value' => old('starter_email'),
                'fullWidth' => true,
            ])
            <div class="admin-form-span-full mb-4" x-data="{ q: '' }">
                <p class="portal-label mb-2">Security groups</p>
                <p class="portal-body-muted text-xs mb-3">
                    Tick the groups this person should join. The Service Desk ticket lists the names so an engineer can add them in Microsoft.
                </p>
                @if(($securityGroups ?? []) === [])
                    <p class="portal-body-muted text-sm">Security groups are not available from Microsoft right now. Name them in additional notes.</p>
                @else
                    <input type="search" x-model="q" placeholder="Filter groups" class="admin-input mb-2" aria-label="Filter security groups">
                    <div class="max-h-52 overflow-y-auto border border-white/10">
                        @foreach($securityGroups as $group)
                            <label class="flex items-center gap-2 px-3 py-2 text-sm text-white/85 border-b border-white/5 last:border-b-0"
                                   x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($group['name'])) }}.includes(q.toLowerCase())">
                                <input type="checkbox" name="security_groups[]" value="{{ $group['id'] }}"
                                       @checked(in_array($group['id'], (array) old('security_groups', []), true))
                                       class="border-onit-border bg-onit-surface text-onit focus:ring-onit">
                                <span>{{ $group['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('security_groups')
                    <p class="text-red-300 text-xs mt-2">{{ $message }}</p>
                @enderror
                @error('security_groups.*')
                    <p class="text-red-300 text-xs mt-2">{{ $message }}</p>
                @enderror
            </div>
            @include('admin.partials.form-field', [
                'label' => 'Equipment / access needed',
                'name' => 'equipment_access',
                'type' => 'textarea',
                'value' => old('equipment_access'),
            ])
            <p class="portal-body-muted text-xs -mt-2 mb-4 admin-form-span-full">e.g. laptop, Microsoft 365, shared mailbox, VPN, printer</p>
            @include('admin.partials.form-field', [
                'label' => 'Additional notes',
                'name' => 'notes',
                'type' => 'textarea',
                'value' => old('notes'),
            ])
            <div class="admin-form-actions">
                <button type="submit" class="cta-btn text-sm">Submit to Service Desk</button>
                <a href="{{ route('contact-support.index') }}" class="portal-body-muted text-sm hover:text-onit self-center">Cancel</a>
            </div>
        </form>
    </x-card>

    <p class="portal-body-muted text-xs mt-6 max-w-2xl">
        Prefer to call? {{ $contact['phone'] }} · {{ $contact['hours'] }} ({{ $contact['timezone_label'] }}) ·
        <a href="mailto:{{ $contact['email'] }}" class="text-onit hover:text-white">{{ $contact['email'] }}</a>
    </p>
</x-app-layout>
