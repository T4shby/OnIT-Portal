<?php

namespace Tests\Unit\Services\SuperOps;

use App\Services\SuperOps\SuperOpsClientMetricsService;
use ReflectionMethod;
use Tests\TestCase;

class SuperOpsWaitingOnClientCountTest extends TestCase
{
    public function test_counts_waiting_on_client_statuses(): void
    {
        $service = $this->app->make(SuperOpsClientMetricsService::class);
        $rm = new ReflectionMethod(SuperOpsClientMetricsService::class, 'countWaitingOnClient');
        $rm->setAccessible(true);

        $count = $rm->invoke($service, [
            ['status' => 'Waiting on Client'],
            ['status' => 'Waiting on Customer'],
            ['status' => 'Open'],
            ['status' => 'In Progress'],
            ['status' => 'Waiting on Vendor'],
            ['status' => 'Waiting on third party'],
            ['status' => 'Still Issue'],
            ['status' => 'Still Issue 2'],
            ['status' => 'Closed'],
        ]);

        $this->assertSame(4, $count);
    }

    public function test_third_party_is_open_but_not_waiting_on_the_client(): void
    {
        $service = $this->app->make(SuperOpsClientMetricsService::class);
        $waiting = new ReflectionMethod(SuperOpsClientMetricsService::class, 'countWaitingOnClient');
        $waiting->setAccessible(true);
        $open = new ReflectionMethod(SuperOpsClientMetricsService::class, 'countOpenTickets');
        $open->setAccessible(true);

        $tickets = [
            ['status' => 'Waiting on third party', 'priority' => 'Medium'],
            ['status' => 'Waiting for 3rd party', 'priority' => 'Low'],
        ];

        $this->assertSame(0, $waiting->invoke($service, $tickets));
        $this->assertSame(2, $open->invoke($service, $tickets));
    }
}
