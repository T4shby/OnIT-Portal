<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntegrationHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_long_running_directory_refresh_as_stuck(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        Cache::put('m365_directory.refresh_queued.'.$client->id, true, now()->addMinutes(30));
        Cache::put(
            'm365_directory.refresh_started.'.$client->id,
            now()->subMinutes(12)->toIso8601String(),
            now()->addMinutes(30),
        );

        $overview = app(IntegrationHealthService::class)->overview();

        $this->assertSame(1, $overview['stuck_count']);
        $this->assertTrue($overview['clients'][0]['is_stuck']);
        $this->assertSame('M365 directory', $overview['clients'][0]['active_process']);
    }
}
