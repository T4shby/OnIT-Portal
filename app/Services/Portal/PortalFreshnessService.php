<?php

namespace App\Services\Portal;

use App\Enums\UserRole;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adaptive refresh cadence for **all** portal customer tenants (not a single client).
 *
 * - Any client-org user with a recent session → hot interval (default 2.5m).
 * - Otherwise during business hours → work-idle interval (default 60m).
 * - Otherwise outside business hours → off-hours interval (default 60m).
 *
 * Staff (super_admin / account_manager) sessions do not count as customers.
 * Tunables live in `settings` (Integration Health UI only - not .env).
 */
class PortalFreshnessService
{
    public const MODE_CACHE_KEY = 'portal.freshness.last_mode';

    /** @var array<string, array{label: string, type: string, default: float|int|string, help: string, group: string}> */
    public const ADMIN_KEYS = [
        'freshness.hot_minutes' => [
            'label' => 'Fast refresh',
            'type' => 'number',
            'default' => 2.5,
            'help' => 'Minutes between pulls while any customer is in the portal.',
            'group' => 'cadence',
            'suffix' => 'min',
        ],
        'freshness.work_idle_minutes' => [
            'label' => 'Idle - business hours',
            'type' => 'number',
            'default' => 60,
            'help' => 'When nobody is online during the daytime window.',
            'group' => 'cadence',
            'suffix' => 'min',
        ],
        'freshness.off_hours_idle_minutes' => [
            'label' => 'Idle - outside hours',
            'type' => 'number',
            'default' => 60,
            'help' => 'When nobody is online overnight / weekends of that window.',
            'group' => 'cadence',
            'suffix' => 'min',
        ],
        'freshness.presence_minutes' => [
            'label' => 'Session counts as active for',
            'type' => 'number',
            'default' => 15,
            'help' => 'How recent a customer session must be to stay in fast mode.',
            'group' => 'presence',
            'suffix' => 'min',
        ],
        'freshness.work_start' => [
            'label' => 'Business day starts',
            'type' => 'text',
            'default' => '07:00',
            'help' => '24h time, e.g. 07:00',
            'group' => 'hours',
            'suffix' => null,
        ],
        'freshness.work_end' => [
            'label' => 'Business day ends',
            'type' => 'text',
            'default' => '19:00',
            'help' => 'Exclusive end (19:00 = until 18:59).',
            'group' => 'hours',
            'suffix' => null,
        ],
        'freshness.timezone' => [
            'label' => 'Timezone',
            'type' => 'text',
            'default' => 'Europe/London',
            'help' => 'PHP timezone name for the window above.',
            'group' => 'hours',
            'suffix' => null,
        ],
    ];

    /**
     * Insert missing freshness.* rows once (never overwrites existing values).
     *
     * @return int Number of keys created
     */
    public function ensureDefaults(): int
    {
        $created = 0;
        foreach (self::ADMIN_KEYS as $key => $meta) {
            if (Setting::where('key', $key)->exists()) {
                continue;
            }
            Setting::set($key, (string) $meta['default']);
            $created++;
        }

        return $created;
    }

    /**
     * Values for the admin form (database settings, else defaults).
     *
     * @return array<string, string>
     */
    public function editableValues(): array
    {
        $this->ensureDefaults();

        $out = [];
        foreach (self::ADMIN_KEYS as $key => $meta) {
            $default = (string) $meta['default'];
            $out[$key] = (string) (Setting::get($key, $default) ?? $default);
        }

        return $out;
    }

    public function snapshot(): array
    {
        return Cache::remember('portal.freshness.snapshot.live', now()->addSeconds(15), function (): array {
            return $this->computeSnapshot();
        });
    }

    /**
     * @return array{
     *   mode: string,
     *   label: string,
     *   interval_minutes: float,
     *   requeue_minutes: float,
     *   soft_window_minutes: float,
     *   customer_sessions: int,
     *   in_business_hours: bool,
     *   timezone: string,
     *   configured: array{
     *     hot_minutes: float,
     *     work_idle_minutes: float,
     *     off_hours_idle_minutes: float,
     *     presence_minutes: float,
     *     work_start: string,
     *     work_end: string,
     *     timezone: string
     *   },
     *   at: string
     * }
     */
    private function computeSnapshot(): array
    {
        $configured = $this->configuredTiming();
        $tz = $configured['timezone'];
        try {
            $now = now($tz);
        } catch (\Throwable) {
            $tz = 'Europe/London';
            $configured['timezone'] = $tz;
            $now = now($tz);
        }

        $inBusinessHours = $this->inBusinessHours($now);
        $customerSessions = $this->activeCustomerSessionCount();

        if ($customerSessions > 0) {
            $mode = 'customer_activity';
            $interval = $configured['hot_minutes'];
            $label = 'Customers online → using Fast refresh';
        } elseif ($inBusinessHours) {
            $mode = 'business_hours_idle';
            $interval = $configured['work_idle_minutes'];
            $label = 'No customers online in business hours → using Idle - business hours';
        } else {
            $mode = 'off_hours_idle';
            $interval = $configured['off_hours_idle_minutes'];
            $label = 'Outside business hours, no customers → using Idle - outside hours';
        }

        $interval = max(0.5, $interval);
        $requeue = max(0.5, $interval * 0.9);
        $soft = max($interval + 1, $interval * 1.15);

        $snapshot = [
            'mode' => $mode,
            'label' => $label,
            'interval_minutes' => $interval,
            'requeue_minutes' => $requeue,
            'soft_window_minutes' => $soft,
            'customer_sessions' => $customerSessions,
            'in_business_hours' => $inBusinessHours,
            'timezone' => $tz,
            'configured' => $configured,
            'at' => now()->toIso8601String(),
        ];

        Cache::put(self::MODE_CACHE_KEY, $snapshot, now()->addHours(6));

        return $snapshot;
    }

    /**
     * Same DB values as the Integration Health timing drawer.
     *
     * @return array{
     *   hot_minutes: float,
     *   work_idle_minutes: float,
     *   off_hours_idle_minutes: float,
     *   presence_minutes: float,
     *   work_start: string,
     *   work_end: string,
     *   timezone: string
     * }
     */
    public function configuredTiming(): array
    {
        return [
            'hot_minutes' => max(0.5, $this->optionFloat('freshness.hot_minutes', 2.5)),
            'work_idle_minutes' => max(1, $this->optionFloat('freshness.work_idle_minutes', 60)),
            'off_hours_idle_minutes' => max(1, $this->optionFloat('freshness.off_hours_idle_minutes', 60)),
            'presence_minutes' => max(1, $this->optionFloat('freshness.presence_minutes', 15)),
            'work_start' => $this->optionString('freshness.work_start', '07:00'),
            'work_end' => $this->optionString('freshness.work_end', '19:00'),
            'timezone' => $this->optionString('freshness.timezone', 'Europe/London'),
        ];
    }

    public function effectiveIntervalMinutes(): float
    {
        return (float) $this->snapshot()['interval_minutes'];
    }

    public function effectiveRequeueMinutes(): float
    {
        return (float) $this->snapshot()['requeue_minutes'];
    }

    public function effectiveSoftWindowMinutes(): float
    {
        return (float) $this->snapshot()['soft_window_minutes'];
    }

    public function isIntervalDue(string $cacheKey): bool
    {
        $seconds = max(30, (int) round($this->effectiveIntervalMinutes() * 60));
        $last = Cache::get($cacheKey);
        $at = is_array($last) ? ($last['at'] ?? null) : (is_string($last) ? $last : null);
        if (! filled($at)) {
            return true;
        }

        return Carbon::parse($at)->lte(now()->subSeconds($seconds));
    }

    public function activeCustomerSessionCount(): int
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasTable('users')) {
            return 0;
        }

        $presenceMinutes = max(1, (int) $this->optionFloat('freshness.presence_minutes', 15));
        $cutoff = now()->subMinutes($presenceMinutes)->getTimestamp();
        $roles = UserRole::clientRoles();
        $roles[] = UserRole::ClientUser->value;
        $roles = array_values(array_unique($roles));

        return (int) Cache::remember(
            'portal.freshness.customer_sessions.'.$cutoff,
            now()->addSeconds(20),
            function () use ($cutoff, $roles): int {
                return (int) DB::table('sessions')
                    ->join('users', 'users.id', '=', 'sessions.user_id')
                    ->whereNotNull('sessions.user_id')
                    ->where('sessions.last_activity', '>=', $cutoff)
                    ->whereNotNull('users.client_id')
                    ->whereIn('users.role', $roles)
                    ->distinct()
                    ->count('sessions.user_id');
            },
        );
    }

    private function optionFloat(string $settingKey, float $default): float
    {
        $fallback = (float) (self::ADMIN_KEYS[$settingKey]['default'] ?? $default);

        return Setting::getFloat($settingKey, $fallback);
    }

    private function optionString(string $settingKey, string $default): string
    {
        $fallback = (string) (self::ADMIN_KEYS[$settingKey]['default'] ?? $default);
        $value = Setting::get($settingKey, $fallback);

        return filled($value) ? (string) $value : $fallback;
    }

    private function inBusinessHours(Carbon $nowLocal): bool
    {
        $start = $this->optionString('freshness.work_start', '07:00');
        $end = $this->optionString('freshness.work_end', '19:00');
        [$sh, $sm] = array_map('intval', explode(':', $start.':0'));
        [$eh, $em] = array_map('intval', explode(':', $end.':0'));

        $startAt = $nowLocal->copy()->setTime($sh, $sm, 0);
        $endAt = $nowLocal->copy()->setTime($eh, $em, 0);

        return $nowLocal->gte($startAt) && $nowLocal->lt($endAt);
    }
}
