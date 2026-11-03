<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\IntegrationHealthAlertService;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHealthAlertServiceTest extends TestCase
{
    public function test_disabled_alerts_do_not_send(): void
    {
        config(['services.integration_health_alerts.enabled' => false]);

        $health = $this->createMock(IntegrationHealthService::class);
        $health->expects($this->never())->method('overview');

        $result = (new IntegrationHealthAlertService($health))->evaluateAndNotify();
        $this->assertFalse($result['sent']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_sends_teams_after_sustained_warning(): void
    {
        config([
            'services.integration_health_alerts.enabled' => true,
            'services.integration_health_alerts.teams_webhook_url' => 'https://example.test/webhook',
            'services.integration_health_alerts.email_to' => '',
            'services.integration_health_alerts.sustained_minutes' => 15,
            'services.integration_health_alerts.cooldown_minutes' => 180,
            'app.url' => 'https://app.onit.ltd',
        ]);

        Http::fake([
            'example.test/*' => Http::response('1', 200),
        ]);

        Cache::put(IntegrationHealthAlertService::STATE_CACHE_KEY, [
            'severity' => 'warning',
            'since' => now()->subMinutes(20)->toIso8601String(),
            'last_sent_at' => null,
        ], now()->addHour());

        $health = $this->createMock(IntegrationHealthService::class);
        $health->method('overview')->willReturn([
            'pipeline' => [
                'severity_level' => 'warning',
                'headline' => 'Auto-refresh (prewarm) is late',
            ],
            'notices' => ['Prewarm last ran 40m ago'],
        ]);

        $result = (new IntegrationHealthAlertService($health))->evaluateAndNotify();

        $this->assertTrue($result['sent']);
        $this->assertSame(['teams'], $result['channels']);
        Http::assertSentCount(1);
    }
}
