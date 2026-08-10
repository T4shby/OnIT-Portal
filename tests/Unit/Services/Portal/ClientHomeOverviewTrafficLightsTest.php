<?php

namespace Tests\Unit\Services\Portal;

use App\Services\Portal\ClientHomeOverviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ClientHomeOverviewTrafficLightsTest extends TestCase
{
    private function health(string $method, object $summary): array
    {
        $service = $this->app->make(ClientHomeOverviewService::class);
        $rm = new ReflectionMethod(ClientHomeOverviewService::class, $method);
        $rm->setAccessible(true);

        return $rm->invoke($service, $summary);
    }

    public static function superOpsProvider(): array
    {
        return [
            'healthy with sla' => [
                (object) ['openTicketsTotal' => 8, 'assetsOffline' => 40, 'assetsTotal' => 64, 'slaMetPercent' => 100],
                'ok',
                'Healthy',
            ],
            'offline ignored' => [
                (object) ['openTicketsTotal' => 0, 'assetsOffline' => 50, 'assetsTotal' => 64, 'slaMetPercent' => 98],
                'ok',
                'Healthy',
            ],
            'issues sla' => [
                (object) ['openTicketsTotal' => 2, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 93],
                'warn',
                'Issues',
            ],
            'issues open backlog' => [
                (object) ['openTicketsTotal' => 12, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 100],
                'warn',
                'Issues',
            ],
            'critical sla' => [
                (object) ['openTicketsTotal' => 0, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 85],
                'bad',
                'Critical',
            ],
            'critical open backlog' => [
                (object) ['openTicketsTotal' => 25, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 100],
                'bad',
                'Critical',
            ],
            'few open tickets still healthy' => [
                (object) ['openTicketsTotal' => 5, 'assetsOffline' => 30, 'assetsTotal' => 64, 'slaMetPercent' => 100],
                'ok',
                'Healthy',
            ],
        ];
    }

    #[DataProvider('superOpsProvider')]
    public function test_superops_health(object $summary, string $tone, string $label): void
    {
        $h = $this->health('superOpsHealth', $summary);
        $this->assertSame($tone, $h['tone']);
        $this->assertSame($label, $h['status_label']);
        $this->assertNotSame('', $h['status_reason'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('offline', $h['status_reason'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('computer', $h['status_reason'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('superops', $h['status_reason'] ?? '');
    }

    public function test_huntress_open_is_issues_until_three(): void
    {
        $one = $this->health('huntressHealth', (object) [
            'openIncidents' => 1,
            'agentsTotal' => 50,
            'agentsUnresponsive' => 0,
        ]);
        $this->assertSame('warn', $one['tone']);
        $this->assertStringContainsString('security case', $one['status_reason']);

        $three = $this->health('huntressHealth', (object) [
            'openIncidents' => 3,
            'agentsTotal' => 50,
            'agentsUnresponsive' => 0,
        ]);
        $this->assertSame('bad', $three['tone']);
    }

    public function test_dropsuite_failed_is_critical(): void
    {
        $ok = $this->health('dropsuiteHealth', (object) ['failedLast24h' => 0]);
        $this->assertSame('ok', $ok['tone']);

        $fail = $this->health('dropsuiteHealth', (object) ['failedLast24h' => 2]);
        $this->assertSame('bad', $fail['tone']);
        $this->assertSame('Critical', $fail['status_label']);
    }

    public function test_m365_oversubscription(): void
    {
        $ok = $this->health('m365Health', (object) [
            'totalSeatsAssigned' => 100,
            'totalSeatsPurchased' => 100,
        ]);
        $this->assertSame('ok', $ok['tone']);

        $over = $this->health('m365Health', (object) [
            'totalSeatsAssigned' => 105,
            'totalSeatsPurchased' => 100,
        ]);
        $this->assertSame('warn', $over['tone']);

        $crit = $this->health('m365Health', (object) [
            'totalSeatsAssigned' => 120,
            'totalSeatsPurchased' => 100,
        ]);
        $this->assertSame('bad', $crit['tone']);
    }
}
