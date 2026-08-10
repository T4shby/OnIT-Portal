<?php

namespace Tests\Unit\Services\Portal;

use App\Models\Client;
use App\Models\User;
use App\Services\Huntress\HuntressIncident;
use App\Services\Huntress\HuntressIncidentListSnapshot;
use App\Services\Huntress\HuntressIncidentService;
use App\Services\Portal\ClientHomeOverviewService;
use Carbon\Carbon;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ClientHomeOverviewThreatsMtdTest extends TestCase
{
    public function test_counts_closed_incidents_in_current_month_only(): void
    {
        $client = new Client(['id' => 9, 'huntress_organization_id' => 'org']);
        $user = new User(['id' => 1]);

        $closedThisMonth = new HuntressIncident(
            id: '1',
            subject: 'Phish',
            status: 'resolved',
            isActive: false,
            severity: null,
            summary: null,
            body: null,
            sentAt: now()->subDays(2),
            closedAt: now()->subDay(),
            updatedAt: now()->subDay(),
            platform: null,
            remediations: [['action' => 'isolate', 'status' => 'completed']],
        );

        $closedLastMonth = new HuntressIncident(
            id: '2',
            subject: 'Old',
            status: 'resolved',
            isActive: false,
            severity: null,
            summary: null,
            body: null,
            sentAt: now()->subMonthsNoOverflow(1),
            closedAt: now()->subMonthsNoOverflow(1)->endOfMonth()->subDay(),
            updatedAt: now()->subMonthsNoOverflow(1)->endOfMonth()->subDay(),
            platform: null,
        );

        $open = new HuntressIncident(
            id: '3',
            subject: 'Live',
            status: 'sent',
            isActive: true,
            severity: null,
            summary: null,
            body: null,
            sentAt: now(),
            closedAt: null,
            updatedAt: now(),
            platform: null,
        );

        $mock = Mockery::mock(HuntressIncidentService::class);
        $mock->shouldReceive('isAvailableForClient')->andReturn(true);
        $mock->shouldReceive('listForClient')->andReturn(new HuntressIncidentListSnapshot(
            incidents: [$closedThisMonth, $closedLastMonth, $open],
            activeCount: 1,
            resolvedCount: 2,
            available: true,
            unavailableReason: null,
            lastRefreshedAt: now(),
            isStale: false,
            refreshInProgress: false,
        ));

        $this->app->instance(HuntressIncidentService::class, $mock);

        $service = $this->app->make(ClientHomeOverviewService::class);
        $mtd = new ReflectionMethod(ClientHomeOverviewService::class, 'huntressThreatsStoppedMtd');
        $mtd->setAccessible(true);

        $this->assertSame(1, $mtd->invoke($service, $client, $user));

        $resp = new ReflectionMethod(ClientHomeOverviewService::class, 'huntressThreatResponsesMtd');
        $resp->setAccessible(true);
        $this->assertSame(1, $resp->invoke($service, $client, $user));
    }
}
