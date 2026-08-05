<?php

namespace Tests\Unit;

use App\Services\Portal\DashboardFeedRegistry;
use App\Services\Portal\Feeds\DropsuiteDashboardFeed;
use App\Services\Portal\Feeds\HuntressDashboardFeed;
use App\Services\Portal\Feeds\M365DirectoryDashboardFeed;
use App\Services\Portal\Feeds\M365InsightsDashboardFeed;
use App\Services\Portal\Feeds\SuperOpsDashboardFeed;
use Tests\TestCase;

class DashboardFeedRegistryTest extends TestCase
{
    public function test_registry_orders_critical_and_optional_feeds(): void
    {
        $registry = app(DashboardFeedRegistry::class);

        $keys = array_map(fn ($f) => $f->key(), $registry->all());
        $this->assertSame([
            'superops',
            'huntress',
            'dropsuite',
            'm365_insights',
            'm365_directory',
        ], $keys);

        $critical = array_map(fn ($f) => $f->key(), $registry->critical());
        $this->assertSame(['superops'], $critical);

        $tiles = array_map(fn ($f) => $f->key(), $registry->overviewTiles());
        $this->assertSame(['superops', 'huntress', 'dropsuite', 'm365_insights'], $tiles);
        $this->assertNotContains('m365_directory', $tiles);
    }

    public function test_adapters_resolve_from_container(): void
    {
        $this->assertInstanceOf(SuperOpsDashboardFeed::class, app(SuperOpsDashboardFeed::class));
        $this->assertInstanceOf(HuntressDashboardFeed::class, app(HuntressDashboardFeed::class));
        $this->assertInstanceOf(DropsuiteDashboardFeed::class, app(DropsuiteDashboardFeed::class));
        $this->assertInstanceOf(M365InsightsDashboardFeed::class, app(M365InsightsDashboardFeed::class));
        $this->assertInstanceOf(M365DirectoryDashboardFeed::class, app(M365DirectoryDashboardFeed::class));
    }
}
