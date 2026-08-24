<x-app-layout title="Contact Support">
    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Contact</h1>
            <h1 class="section-heading-orange">Support</h1>
        </div>
        <p class="portal-body-muted max-w-2xl">
            Reach the On IT Service Desk - log a ticket online, call us during working hours, or request a new starter setup.
        </p>
    </section>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mb-10">
        <div class="portal-card p-5 flex flex-col gap-4">
            <p class="text-onit text-xs font-semibold uppercase tracking-wider">Online ticket</p>
            <h2 class="text-white text-lg font-semibold m-0">Log a ticket</h2>
            <p class="portal-body-muted text-sm leading-relaxed flex-1 m-0">
                Tell us what you need help with. We will pick it up in SuperOps and keep the conversation there.
            </p>
            @if($apiConfigured && $clientLinked)
                <a href="{{ route('support.create') }}" class="cta-btn text-sm text-center">Log a ticket online</a>
                <a href="{{ route('support.index') }}" class="text-sm text-onit hover:text-white text-center">View my tickets</a>
            @else
                <p class="text-amber-200/90 text-sm m-0">
                    Online ticketing is not available for your organisation yet. Please call or email us below.
                </p>
            @endif
        </div>

        <div class="portal-card p-5 flex flex-col gap-4">
            <p class="text-onit text-xs font-semibold uppercase tracking-wider">Call or visit</p>
            <h2 class="text-white text-lg font-semibold m-0">Phone &amp; hours</h2>
            <div class="portal-body-muted text-sm leading-relaxed flex-1 space-y-3">
                <p class="m-0">
                    <span class="text-white/70 block text-xs uppercase tracking-wide mb-1">Phone</span>
                    <a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}" class="text-white font-semibold text-base hover:text-onit">
                        {{ $contact['phone'] }}
                    </a>
                </p>
                <p class="m-0">
                    <span class="text-white/70 block text-xs uppercase tracking-wide mb-1">Hours</span>
                    <span class="text-white">{{ $contact['hours'] }} ({{ $contact['timezone_label'] }})</span>
                </p>
                <p class="m-0">
                    <span class="text-white/70 block text-xs uppercase tracking-wide mb-1">Email</span>
                    <a href="mailto:{{ $contact['email'] }}" class="text-onit hover:text-white break-all">{{ $contact['email'] }}</a>
                </p>
                <p class="m-0">
                    <span class="text-white/70 block text-xs uppercase tracking-wide mb-1">Address</span>
                    @foreach($contact['address_lines'] as $line)
                        <span class="block text-white/90">{{ $line }}</span>
                    @endforeach
                </p>
            </div>
        </div>

        <div class="portal-card p-5 flex flex-col gap-4 sm:col-span-2 lg:col-span-1">
            <p class="text-onit text-xs font-semibold uppercase tracking-wider">Joiners</p>
            <h2 class="text-white text-lg font-semibold m-0">New starter</h2>
            <p class="portal-body-muted text-sm leading-relaxed flex-1 m-0">
                Request accounts, licences, and kit for someone joining your organisation. This opens a Service Desk ticket for On IT.
            </p>
            @if($apiConfigured && $clientLinked)
                <a href="{{ route('contact-support.new-starter') }}" class="cta-btn text-sm text-center">New starter form</a>
            @else
                <p class="text-amber-200/90 text-sm m-0">
                    Please email {{ $contact['email'] }} or call {{ $contact['phone'] }} for new starter requests.
                </p>
            @endif
        </div>
    </div>
</x-app-layout>
