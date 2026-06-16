<x-app-layout>
    <section class="portal-hero mb-10">
        <div class="portal-hero-accent"></div>
        <div class="relative">
            <p class="portal-section-title">Welcome back</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">{{ $user->name }}</h1>
            @if($user->client)
                <p class="mt-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-sm text-slate-200 backdrop-blur-sm">
                    <span class="h-2 w-2 rounded-full bg-onit"></span>
                    {{ $user->client->name }}
                </p>
            @endif
            <p class="mt-5 max-w-2xl text-base leading-relaxed text-slate-300">
                Jump straight into your services below. Everything is linked to your organisation — no extra passwords needed.
            </p>
        </div>
    </section>

    @if($portalLinks->isNotEmpty())
        <section>
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <p class="portal-section-title">Services</p>
                    <h2 class="mt-1 text-2xl font-bold text-onit-ink">Your portals</h2>
                </div>
                <p class="hidden text-sm text-slate-500 sm:block">{{ $portalLinks->count() }} available</p>
            </div>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </section>
    @else
        <x-card class="border-dashed">
            <x-empty-state
                title="No portals configured"
                description="Ask your administrator to set up SuperOps and Pax8 links."
            />
        </x-card>
    @endif

    <section class="mt-10 rounded-2xl border border-slate-200/80 bg-white/80 p-6 backdrop-blur-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="portal-section-title">Need help?</p>
                <h3 class="mt-1 text-lg font-semibold text-onit-ink">Contact your IT team</h3>
                <p class="mt-1 text-sm text-slate-500">For access issues or new services, reach out to On IT support.</p>
            </div>
            <a href="https://onit.ltd" target="_blank" rel="noopener noreferrer" class="portal-btn-secondary shrink-0">
                Visit onit.ltd
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            </a>
        </div>
    </section>
</x-app-layout>
