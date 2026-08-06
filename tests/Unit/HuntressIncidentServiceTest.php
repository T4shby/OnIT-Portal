<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Huntress\HuntressIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HuntressIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
        ]);
    }

    public function test_refresh_counts_active_and_resolved(): void
    {
        Http::fake([
            'https://api.huntress.io/v1/incident_reports*' => Http::response([
                'incident_reports' => [
                    ['id' => 1, 'organization_id' => '99', 'subject' => 'A', 'status' => 'open', 'summary' => 'x'],
                    ['id' => 2, 'organization_id' => '99', 'subject' => 'B', 'status' => 'closed', 'summary' => 'y'],
                    ['id' => 3, 'organization_id' => 'other', 'subject' => 'Leak', 'status' => 'open', 'summary' => 'no'],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['huntress_organization_id' => '99']);
        $payload = app(HuntressIncidentService::class)->refreshAndStore($client);

        $this->assertSame(1, $payload['active_count']);
        $this->assertSame(1, $payload['resolved_count']);
        $this->assertCount(2, $payload['incidents']);
    }

    public function test_find_blocks_other_organisation(): void
    {
        Http::fake([
            'https://api.huntress.io/v1/incident_reports/55' => Http::response([
                'incident_report' => [
                    'id' => 55,
                    'organization_id' => 'evil-org',
                    'subject' => 'Nope',
                    'status' => 'open',
                    'summary' => 'secret',
                ],
            ], 200),
        ]);

        $client = Client::factory()->create(['huntress_organization_id' => 'our-org']);
        $case = app(HuntressIncidentService::class)->findForClient($client, '55');

        $this->assertNull($case);
    }
}
