<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Staff-triggered, per-client background jobs (Apply SCIM, Retry SCIM export,
 * Wire Client SSO, Bootstrap Entra) are ShouldBeUnique on the client id. A second
 * dispatch while the first is pending or running is therefore dropped by the
 * framework without any signal - including the new form values it carried
 * (pass 7, L9). Controllers call claim() first and tell the user when it fails,
 * instead of reporting "running in the background" for work that was discarded.
 *
 * The using class must define IN_FLIGHT_KEY_PREFIX and a static markQueued(int).
 */
trait ClaimsInFlightSlot
{
    /**
     * Atomically take the per-client in-flight slot (Cache::add is insert-if-absent
     * on the database store, so two simultaneous submissions cannot both win).
     * On success this also runs markQueued() so the job's own bookkeeping
     * (clearing the last result, etc.) is unchanged. Returns false when a run is
     * already pending or running; the slot is freed by the job's finish()/failed().
     */
    public static function claim(int $clientId): bool
    {
        if (! Cache::add(static::IN_FLIGHT_KEY_PREFIX.$clientId, true, now()->addMinutes(15))) {
            return false;
        }

        static::markQueued($clientId);

        return true;
    }

    public static function isInFlight(int $clientId): bool
    {
        return Cache::has(static::IN_FLIGHT_KEY_PREFIX.$clientId);
    }
}
