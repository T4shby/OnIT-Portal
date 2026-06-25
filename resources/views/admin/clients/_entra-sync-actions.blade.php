@if(isset($client) && $client->exists && $client->hasEntraSyncConfigured())
    @if(config('services.entra_sync.enabled'))
        <div
            class="mt-4"
            x-data="{
                syncing: false,
                active: null,
                message: '',
                startSync(event, mode, message) {
                    if (this.syncing) {
                        event.preventDefault();
                        return;
                    }
                    const confirmMessage = event.target.dataset.confirm;
                    if (confirmMessage && ! window.confirm(confirmMessage)) {
                        event.preventDefault();
                        return;
                    }
                    this.syncing = true;
                    this.active = mode;
                    this.message = message;
                },
            }"
        >
            <div
                x-show="syncing"
                x-cloak
                class="mb-3 flex items-center gap-3 border border-onit/35 bg-onit/10 px-4 py-3 text-sm text-white/90"
                role="status"
                aria-live="polite"
            >
                <svg class="h-5 w-5 shrink-0 animate-spin text-onit" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="message"></span>
            </div>

            <div class="flex flex-wrap gap-3">
                <form
                    method="POST"
                    action="{{ route('admin.clients.sync-entra', $client) }}"
                    @submit="startSync($event, 'dry', 'Running dry run sync…')"
                >
                    @csrf
                    <input type="hidden" name="dry_run" value="1">
                    <button
                        type="submit"
                        class="cta-btn-ghost text-sm px-6 py-3"
                        :disabled="syncing"
                        :class="{ 'opacity-60 cursor-wait': syncing }"
                    >
                        <span x-show="!(syncing && active === 'dry')" class="inline-flex items-center gap-2">Dry run sync</span>
                        <span x-show="syncing && active === 'dry'" x-cloak class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Dry run…
                        </span>
                    </button>
                </form>
                <form
                    method="POST"
                    action="{{ route('admin.clients.sync-entra', $client) }}"
                    data-confirm="Run Entra sync now? Users no longer licensed (and not shared mailboxes) will be deactivated."
                    @submit="startSync($event, 'live', 'Syncing with Microsoft Entra… updating portal users, group, SuperOps last names, and SCIM (one user at a time). Large tenants can take several minutes — keep this page open.')"
                >
                    @csrf
                    <button
                        type="submit"
                        class="cta-btn text-sm px-6 py-3"
                        :disabled="syncing"
                        :class="{ 'opacity-80 cursor-wait': syncing && active === 'live' }"
                    >
                        <span x-show="!(syncing && active === 'live')" class="inline-flex items-center gap-2">Sync now</span>
                        <span x-show="syncing && active === 'live'" x-cloak class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Syncing…
                        </span>
                    </button>
                </form>
            </div>
        </div>
    @else
        <p class="portal-body-muted mt-4 text-xs">Set <code class="text-onit">ENTRA_SYNC_ENABLED=true</code> on the server to run sync.</p>
    @endif
@elseif(isset($client) && $client->exists)
    <p class="portal-body-muted mt-4 text-xs">Save tenant ID and enable sync to run from here.</p>
@endif
