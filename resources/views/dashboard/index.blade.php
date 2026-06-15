<x-app-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Welcome back, {{ $user->name }}</h1>
        @if($user->client)
            <p class="text-slate-500 mt-1">{{ $user->client->name }}</p>
        @endif
    </div>

    @if($portalLinks->isNotEmpty())
        <section>
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Your Portals</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 max-w-3xl">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </section>
    @else
        <x-card>
            <x-empty-state
                title="No portals configured"
                description="Ask your administrator to set up SuperOps and Pax8 links."
            />
        </x-card>
    @endif
</x-app-layout>
