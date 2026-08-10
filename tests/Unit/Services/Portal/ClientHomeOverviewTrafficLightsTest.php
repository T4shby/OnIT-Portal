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
            'healthy' => [(object) ['openTicketsTotal' => 0, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 100], 'ok', 'Healthy'],
            'issues open' => [(object) ['openTicketsTotal' => 3, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 100], 'warn', 'Issues'],
            'issues offline' => [(object) ['openTicketsTotal' => 0, 'assetsOffline' => 2, 'assetsTotal' => 50, 'slaMetPercent' => 100], 'warn', 'Issues'],
            'issues sla' => [(object) ['openTicketsTotal' => 0, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 93], 'warn', 'Issues'],
            'critical open' => [(object) ['openTicketsTotal' => 15, 'assetsOffline' => 0, 'assetsTotal' => 50, 'slaMetPercent' => 100], 'bad', 'Critical'],
            'critical offline share' => [(object) ['openTicketsTotal' => 0, 'assetsOffline' => 20, 'assetsTotal' => 64, 'slaMetPercent' => 100], 'bad', 'Critical'],
        ];
    }

    #[DataProvider('superOpsProvider')]
    public function test_superops_health(object $summary, string $tone, string $label): void
    {
        $h = $this->health('superOpsHealth', $summary);
        $this->assertSame($tone, $h['tone']);
        $this->assertSame($label, $h['status_label']);
        $this->assertNotSame('', $h['status_reason'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('sla', $h['status_reason'] ?? 'x');
        $this->assertStringNotContainsStringIgnoringCase('superops', $h['status_reason'] ?? 'x');
        $this->assertStringNotContainsStringIgnoringCase('graph', $h['status_reason'] ?? 'x');
    }

    public function test_offline_reason_is_plain_language(): void
    {
        $h = $this->health('superOpsHealth', (object) [
            'openTicketsTotal' => 0,
            'assetsOffline' => 31,
            'assetsTotal' => 64,
            'slaMetPercent' => 100,
        ]);
        $this->assertSame('bad', $h['tone']);
        $this->assertStringContainsString('computers are offline', $h['status_reason']);
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
        $this->assertStringContainsString('backup', strtolower($ok['status_reason']));

        $fail = $this->health('dropsuiteHealth', (object) ['failedLast24h' => 2]);
        $this->assertSame('bad', $fail['tone']);
        $this->assertSame('Critical', $fail['status_label']);
        $this->assertStringContainsString('backup', strtolower($fail['status_reason']));
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
        $this->assertStringContainsString('licence', strtolower($over['status_reason']));

        $crit = $this->health('m365Health', (object) [
            'totalSeatsAssigned' => 120,
            'totalSeatsPurchased' => 100,
        ]);
        $this->assertSame('bad', $crit['tone']);
    }
}
