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
            ['status' => 'Closed'],
        ]);

        $this->assertSame(2, $count);
    }
}
