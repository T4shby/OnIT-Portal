<x-app-layout>

    <section class="mb-10 sm:mb-12">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Logged in</h1>
            <h1 class="section-heading-orange">{{ $user->name }}</h1>
        </div>
        <p class="portal-body-muted">{{ $user->email }}</p>

        @if($user->client)
            <div class="mt-8 border-l-[3px] border-white/10 pl-6">
                <p class="portal-label">Organisation</p>
                <p class="portal-card-title mt-2">{{ $user->client->name }}</p>
            </div>
        @else
            <div class="mt-8 border-l-[3px] border-white/10 pl-6">
                <p class="portal-label">Access level</p>
                <p class="portal-card-title mt-2">On IT administrator</p>
            </div>
        @endif
    </section>

    @if($portalLinks->isNotEmpty())
        <section class="mb-10 sm:mb-12">
            <div class="mb-8">
                <div class="orange-rule"></div>
                <div class="heading-stack">
                    <h2 class="section-heading-white">Your</h2>
                    <h2 class="section-heading-orange">Portals</h2>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </section>
    @else
        <x-card class="mb-10 sm:mb-12">
            <x-empty-state
                title="No portals configured"
                description="Ask your administrator to set up SuperOps and Pax8 links."
            />
        </x-card>
    @endif

    <section class="portal-panel">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="portal-label mb-2">Support</p>
                <h3 class="portal-card-title">Need help?</h3>
                <p class="portal-body-muted mt-2">Contact On IT support for access or new services.</p>
            </div>
            <a href="https://onit.ltd" target="_blank" rel="noopener noreferrer" class="cta-btn-ghost w-full sm:w-auto justify-center shrink-0">
                Visit onit.ltd
            </a>
        </div>
    </section>

</x-app-layout>
