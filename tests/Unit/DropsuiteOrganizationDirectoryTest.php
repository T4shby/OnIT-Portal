<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteOrganizationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropsuiteOrganizationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_strips_legal_suffix_and_nfr_tag(): void
    {
        $directory = app(DropsuiteOrganizationDirectory::class);

        $this->assertSame(
            'on it',
            $directory->normalizeName('On IT LTD [789e] - NFR')
        );
        $this->assertSame('yorpower', $directory->normalizeName('YorPower Ltd'));
    }

    public function test_suggests_live_client_by_name_and_skips_inactive(): void
    {
        $live = Client::factory()->create(['name' => 'YorPower', 'is_active' => true]);
        Client::factory()->create(['name' => 'YorPower Archive', 'is_active' => false]);
        Client::factory()->create(['name' => 'Finishing Design Services', 'is_active' => true]);

        $directory = app(DropsuiteOrganizationDirectory::class);
        $match = $directory->suggestClient(
            ['organization_id' => '6182', 'name' => 'YorPower'],
            Client::query()->get()
        );

        $this->assertNotNull($match);
        $this->assertTrue($live->is($match));
    }

    public function test_suggests_from_primary_domain_host(): void
    {
        $client = Client::factory()->create([
            'name' => 'Schneider FM',
            'primary_domain' => 'schneiderfm.co.uk',
            'is_active' => true,
        ]);

        $directory = app(DropsuiteOrganizationDirectory::class);
        $match = $directory->suggestClient(
            ['organization_id' => '13469', 'name' => 'Schneiderfm.co.uk'],
            Client::query()->get()
        );

        $this->assertNotNull($match);
        $this->assertTrue($client->is($match));
    }
}
