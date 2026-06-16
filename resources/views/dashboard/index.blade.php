<x-app-layout>

    <section class="portal-hero mb-8 sm:mb-10">
        <h1 class="text-xl font-semibold sm:text-2xl text-white break-words">{{ $user->name }}</h1>
        @if($user->client)
            <p class="mt-2 text-sm text-slate-400">{{ $user->client->name }}</p>
        @endif
    </section>



    @if($portalLinks->isNotEmpty())

        <section>

            <div class="mb-5 flex items-baseline justify-between gap-4">

                <h2 class="text-lg font-semibold text-onit-ink">Your portals</h2>

                <span class="text-xs text-slate-500">{{ $portalLinks->count() }} available</span>

            </div>



            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

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



    <section class="mt-8 sm:mt-10 border border-slate-200 bg-white p-5 sm:p-6">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

            <div>

                <h3 class="text-base font-semibold text-onit-ink">Need help?</h3>

                <p class="mt-1 text-sm text-slate-500">Contact On IT support for access or new services.</p>

            </div>

            <a href="https://onit.ltd" target="_blank" rel="noopener noreferrer" class="portal-btn-secondary w-full sm:w-auto justify-center shrink-0">

                Visit onit.ltd

            </a>

        </div>

    </section>

</x-app-layout>

