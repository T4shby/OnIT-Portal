<?php

namespace Tests\Unit\Services\Portal;

use App\Models\Client;
use App\Services\Portal\ClientHomeOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHomeLastMonthViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_month_view_uses_stored_figures_and_keeps_recent_activity(): void
    {
        $client = Client::factory()->create();
        $service = $this->app->make(ClientHomeOverviewService::class);
        $asOf = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $overview = [
            'client' => $client,
            'organisation_wide' => true,
            'columns' => [
                [
                    'key' => 'superops',
                    'state' => 'live',
                    'tone' => 'ok',
                    'status_label' => 'Healthy',
                    'status_reason' => 'Fine today',
                    'metrics' => [
                        ['label' => 'Open tickets', 'value' => '9', 'kind' => 'ok'],
                        ['label' => 'Devices', 'value' => '4', 'kind' => 'ok'],
                    ],
                ],
                [
                    'key' => 'huntress',
                    'state' => 'not_sold',
                    'tone' => 'muted',
                    'metrics' => [
                        ['label' => 'Threats stopped', 'value' => '0', 'kind' => 'ok'],
                    ],
                ],
            ],
            'activity' => [
                'status' => 'live',
                'items' => [['title' => 'Today ticket']],
            ],
            'month_compare' => [
                'available' => true,
                'as_of' => $asOf,
                'services' => [
                    'superops' => [
                        'band' => 'warn',
                        'label' => 'Issues',
                        'status_reason' => 'More tickets last month',
                        'metrics' => ['Open tickets' => '2'],
                    ],
                    'huntress' => [
                        'band' => 'ok',
                        'metrics' => ['Threats stopped' => '5'],
                    ],
                ],
                'value_deltas' => [
                    ['label' => 'Tickets we closed', 'current' => '1', 'previous' => '8'],
                ],
            ],
        ];

        $last = $service->viewPreviousMonth($overview);

        $this->assertSame('last', $last['viewing_period']);
        $this->assertSame('2', $last['columns'][0]['metrics'][0]['value']);
        $this->assertSame('Not stored', $last['columns'][0]['metrics'][1]['value']);
        $this->assertSame('warn', $last['columns'][0]['tone']);
        $this->assertSame('Issues', $last['columns'][0]['status_label']);
        $this->assertSame('0', $last['columns'][1]['metrics'][0]['value']);
        $this->assertSame('not_sold', $last['columns'][1]['state']);
        $this->assertSame('Today ticket', $last['activity']['items'][0]['title']);
        $this->assertSame('1', $last['month_compare']['value_deltas'][0]['current']);
        $this->assertStringContainsString('as of', $last['period_label']);

        $missing = $service->viewPreviousMonth(array_merge($overview, [
            'month_compare' => ['available' => false],
        ]));

        $this->assertSame('this', $missing['viewing_period']);
        $this->assertSame('9', $missing['columns'][0]['metrics'][0]['value']);
    }
}
