<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Portal\ClientProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientProductServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_products_are_entitled_after_migration_backfill_shape(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => '111',
            'huntress_organization_id' => null,
            'product_entitlements' => [
                'superops' => ['entitled' => true, 'entitled_at' => now()->toIso8601String()],
            ],
        ]);

        $svc = app(ClientProductService::class);

        $this->assertTrue($svc->isEntitled($client, 'superops'));
        $this->assertTrue($svc->isMapped($client, 'superops'));
        $this->assertFalse($svc->isEntitled($client, 'huntress'));
        $this->assertSame(ClientProductService::STATUS_NOT_SOLD, $svc->status($client, 'huntress'));
    }

    public function test_entitled_without_mapping_is_setup_needed(): void
    {
        $client = Client::factory()->create([
            'huntress_organization_id' => null,
            'product_entitlements' => [
                'huntress' => ['entitled' => true],
            ],
        ]);

        $svc = app(ClientProductService::class);

        $this->assertTrue($svc->isEntitled($client, 'huntress'));
        $this->assertFalse($svc->isMapped($client, 'huntress'));
        $this->assertSame(ClientProductService::STATUS_SETUP_NEEDED, $svc->status($client, 'huntress'));
        $this->assertTrue($svc->needsAccountManagerHelp($client, 'huntress'));
    }

    public function test_apply_entitlements_respects_not_sold(): void
    {
        $client = Client::factory()->create([
            'huntress_organization_id' => '999',
            'product_entitlements' => [
                'huntress' => ['entitled' => true],
            ],
        ]);

        $svc = app(ClientProductService::class);
        $svc->applyEntitlements($client, ['huntress' => false]);
        $client->refresh();

        $this->assertFalse($svc->isEntitled($client, 'huntress'));
        $this->assertTrue($svc->isMapped($client, 'huntress'));
        $svc->syncEntitlementsFromMappings($client);
        $client->refresh();
        $this->assertFalse($svc->isEntitled($client, 'huntress'));
    }

    public function test_pax8_is_licence_vendor_not_service(): void
    {
        $svc = app(ClientProductService::class);

        $this->assertTrue($svc->isLicenceVendor('pax8'));
        $this->assertFalse($svc->isLicenceVendor('superops'));
        $this->assertArrayHasKey('pax8', $svc->licenceVendorCatalog());
        $this->assertArrayNotHasKey('pax8', $svc->serviceCatalog());

        $client = Client::factory()->create([
            'product_entitlements' => ['pax8' => ['entitled' => false]],
        ]);
        $this->assertSame('Not assigned', $svc->statusLabel($svc->status($client, 'pax8'), 'pax8'));
        $this->assertFalse($svc->shouldRefresh($client, 'pax8'));
        $this->assertFalse($svc->shouldShowForViewer($client, 'pax8', null));
        $this->assertFalse($svc->shouldShowOverviewTile($client, 'pax8', null));
    }

    public function test_overview_tile_width_is_full_width(): void
    {
        $svc = app(ClientProductService::class);

        // Row-based fractional widths were replaced by a CSS grid in the view;
        // the helper now always returns full width regardless of tile count.
        $this->assertSame('w-full', $svc->overviewTileWidthClass(0, 1));
        $this->assertSame('w-full', $svc->overviewTileWidthClass(0, 2));
        $this->assertSame('w-full', $svc->overviewTileWidthClass(1, 2));
        $this->assertSame('w-full', $svc->overviewTileWidthClass(0, 5));
        $this->assertSame('w-full', $svc->overviewTileWidthClass(4, 5));
    }

    public function test_client_admin_overview_shows_not_sold_requester_does_not(): void
    {
        $client = Client::factory()->create([
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'huntress' => ['entitled' => false],
            ],
            'superops_account_id' => '111',
        ]);

        $svc = app(ClientProductService::class);

        $admin = \App\Models\User::factory()->create([
            'client_id' => $client->id,
            'role' => \App\Enums\UserRole::ClientAdmin,
        ]);
        $requester = \App\Models\User::factory()->create([
            'client_id' => $client->id,
            'role' => \App\Enums\UserRole::ClientRequester,
        ]);

        $this->assertTrue($svc->shouldShowOverviewTile($client, 'huntress', $admin));
        $this->assertFalse($svc->shouldShowForViewer($client, 'huntress', $admin));
        $this->assertFalse($svc->shouldShowOverviewTile($client, 'huntress', $requester));
        $this->assertTrue($svc->shouldShowOverviewTile($client, 'superops', $admin));
    }
}
