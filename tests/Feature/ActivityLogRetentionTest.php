<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ActivityLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_logs_older_than_retain_days(): void
    {
        config(['app.activity_log_retain_days' => 90]);

        $old = ActivityLog::factory()->create([
            'created_at' => now()->subDays(91),
            'updated_at' => now()->subDays(91),
        ]);
        $keep = ActivityLog::factory()->create([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $keep->id]);
    }

    public function test_uses_x_real_ip_when_remote_addr_is_loopback(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = Request::create('/dashboard', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.50',
        ]);
        $this->app->instance('request', $request);

        app(ActivityLogService::class)->log('user.login');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.login',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.50',
        ]);
    }

    public function test_still_records_loopback_when_that_is_the_only_ip(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = Request::create('/dashboard', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $this->app->instance('request', $request);

        app(ActivityLogService::class)->log('user.login');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.login',
            'ip_address' => '127.0.0.1',
        ]);
    }
}
