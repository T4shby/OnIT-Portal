<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Push alerts when Integration Health stays warning/critical (not a daily digest).
 * ADR-023: in-app IH remains primary; this is an optional ops hook.
 */
class IntegrationHealthAlertService
{
    public const STATE_CACHE_KEY = 'integration_health.alert_state';

    public function __construct(private IntegrationHealthService $health) {}

    /**
     * @return array{sent: bool, reason: string, severity: string, channels: list<string>}
     */
    public function evaluateAndNotify(): array
    {
        if (! $this->enabled()) {
            return ['sent' => false, 'reason' => 'disabled', 'severity' => 'ok', 'channels' => []];
        }

        $overview = $this->health->overview(null, false);
        $pipeline = is_array($overview['pipeline'] ?? null) ? $overview['pipeline'] : [];
        $severity = (string) ($pipeline['severity_level'] ?? 'ok');
        $headline = (string) ($pipeline['headline'] ?? 'Integration Health');
        $notices = is_array($overview['notices'] ?? null) ? $overview['notices'] : [];

        if (! in_array($severity, ['warning', 'critical'], true)) {
            Cache::forget(self::STATE_CACHE_KEY);

            return ['sent' => false, 'reason' => 'healthy', 'severity' => $severity, 'channels' => []];
        }

        $now = now();
        $state = Cache::get(self::STATE_CACHE_KEY);
        $state = is_array($state) ? $state : [];

        $since = isset($state['since']) ? \Illuminate\Support\Carbon::parse($state['since']) : $now;
        $lastSeverity = (string) ($state['severity'] ?? '');
        if ($lastSeverity !== $severity) {
            $since = $now;
        }

        $sustainedMinutes = (int) config('services.integration_health_alerts.sustained_minutes', 15);
        $cooldownMinutes = (int) config('services.integration_health_alerts.cooldown_minutes', 180);
        $ageMinutes = $since->diffInMinutes($now);

        $lastSentAt = isset($state['last_sent_at'])
            ? \Illuminate\Support\Carbon::parse($state['last_sent_at'])
            : null;
        $cooledDown = $lastSentAt === null || $lastSentAt->diffInMinutes($now) >= $cooldownMinutes;

        Cache::put(self::STATE_CACHE_KEY, [
            'severity' => $severity,
            'since' => $since->toIso8601String(),
            'last_sent_at' => $lastSentAt?->toIso8601String(),
            'headline' => $headline,
        ], now()->addDays(2));

        if ($ageMinutes < $sustainedMinutes) {
            return [
                'sent' => false,
                'reason' => "waiting_sustained ({$ageMinutes}/{$sustainedMinutes}m)",
                'severity' => $severity,
                'channels' => [],
            ];
        }

        if (! $cooledDown) {
            return [
                'sent' => false,
                'reason' => 'cooldown',
                'severity' => $severity,
                'channels' => [],
            ];
        }

        $channels = $this->dispatch($severity, $headline, $notices);
        if ($channels === []) {
            return ['sent' => false, 'reason' => 'no_channels_configured', 'severity' => $severity, 'channels' => []];
        }

        Cache::put(self::STATE_CACHE_KEY, [
            'severity' => $severity,
            'since' => $since->toIso8601String(),
            'last_sent_at' => $now->toIso8601String(),
            'headline' => $headline,
        ], now()->addDays(2));

        return ['sent' => true, 'reason' => 'sent', 'severity' => $severity, 'channels' => $channels];
    }

    public function enabled(): bool
    {
        return (bool) config('services.integration_health_alerts.enabled', false);
    }

    /**
     * @param  list<string>  $notices
     * @return list<string>
     */
    private function dispatch(string $severity, string $headline, array $notices): array
    {
        $channels = [];
        $url = rtrim((string) config('app.url'), '/').'/admin/integration-health';
        $bodyLines = array_slice(array_values(array_filter($notices)), 0, 8);
        $text = strtoupper($severity).': '.$headline."\n"
            ."Open: {$url}\n"
            .($bodyLines !== [] ? implode("\n", $bodyLines) : 'See Integration Health for detail.');

        $webhook = trim((string) config('services.integration_health_alerts.teams_webhook_url', ''));
        if ($webhook !== '') {
            try {
                $response = Http::timeout(12)->post($webhook, [
                    'text' => $text,
                ]);
                if ($response->successful()) {
                    $channels[] = 'teams';
                } else {
                    Log::warning('IH Teams alert failed', ['status' => $response->status()]);
                }
            } catch (Throwable $e) {
                Log::warning('IH Teams alert exception', ['error' => $e->getMessage()]);
            }
        }

        $emails = $this->alertEmails();
        if ($emails !== []) {
            try {
                Mail::raw($text, function ($message) use ($emails, $severity, $headline): void {
                    $message->to($emails)
                        ->subject('[On IT Portal] Integration Health '.$severity.': '.$headline);
                });
                $channels[] = 'email';
            } catch (Throwable $e) {
                Log::warning('IH email alert exception', ['error' => $e->getMessage()]);
            }
        }

        return $channels;
    }

    /**
     * @return list<string>
     */
    private function alertEmails(): array
    {
        $raw = (string) config('services.integration_health_alerts.email_to', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            preg_split('/[\s,;]+/', $raw) ?: [],
        ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
