<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Gdap\GdapSnapshotPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GdapSnapshotPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_mark_is_a_tick_when_gdap_is_active_or_expiring(): void
    {
        config(['services.gdap.enabled' => true]);

        foreach (['ready', 'expiring'] as $status) {
            $client = Client::factory()->create([
                'gdap_snapshot' => ['status' => $status],
            ]);

            $this->assertSame('done', app(GdapSnapshotPresenter::class)->listMark($client)['mark']);
        }
    }

    public function test_list_mark_is_a_cross_when_gdap_is_not_set_up(): void
    {
        config(['services.gdap.enabled' => true]);

        foreach (['missing', 'approval_pending', 'unassigned', 'incomplete'] as $status) {
            $client = Client::factory()->create([
                'gdap_snapshot' => ['status' => $status],
            ]);

            $this->assertSame('missing', app(GdapSnapshotPresenter::class)->listMark($client)['mark']);
        }
    }

    public function test_list_mark_is_unknown_when_gdap_cannot_be_checked(): void
    {
        config(['services.gdap.enabled' => true]);

        foreach (['unchecked', 'error', 'needs_tenant'] as $status) {
            $client = Client::factory()->create([
                'gdap_snapshot' => ['status' => $status],
            ]);

            $this->assertSame('unknown', app(GdapSnapshotPresenter::class)->listMark($client)['mark']);
        }

        config(['services.gdap.enabled' => false]);
        $off = Client::factory()->create();

        $this->assertSame('unknown', app(GdapSnapshotPresenter::class)->listMark($off)['mark']);
    }
}
