<x-app-layout>

    <section class="portal-hero mb-10 sm:mb-12">
        <p class="text-sm text-slate-400">Signed in as</p>
        <h1 class="mt-1 text-2xl font-semibold text-white sm:text-3xl break-words">{{ $user->name }}</h1>
        @if($user->client)
            <p class="mt-3 text-sm text-slate-300">{{ $user->client->name }}</p>
        @endif
    </section>

    @if($portalLinks->isNotEmpty())
        <section class="mb-10 sm:mb-12">
            <h2 class="mb-5 text-xl font-semibold text-onit-ink sm:mb-6">Your portals</h2>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </section>
    @else
        <x-card class="mb-10 sm:mb-12 !ring-0 border-2 border-dashed border-slate-300 shadow-none">
            <x-empty-state
                title="No portals configured"
                description="Ask your administrator to set up SuperOps and Pax8 links."
            />
        </x-card>
    @endif

    <section class="portal-panel">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-onit-ink">Need help?</h3>
                <p class="mt-1 text-sm text-slate-500">Contact On IT support for access or new services.</p>
            </div>
            <a href="https://onit.ltd" target="_blank" rel="noopener noreferrer" class="portal-btn-secondary w-full sm:w-auto justify-center shrink-0">
                Visit onit.ltd
            </a>
        </div>
    </section>

</x-app-layout>
