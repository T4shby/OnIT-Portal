<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\ClientMetricDailySnapshot;
use Carbon\CarbonInterface;

class ClientMetricSnapshotService
{
    /**
     * @param  array{
     *   overall_band: ?string,
     *   columns: list<array<string, mixed>>,
     *   value: array<string, mixed>|null
     * }  $bundle
     */
    public function captureBundle(Client $client, array $bundle, ?CarbonInterface $forDate = null): ClientMetricDailySnapshot
    {
        $date = ($forDate ?? now())->copy()->startOfDay();
        $services = [];
        foreach ($bundle['columns'] ?? [] as $col) {
            if (! is_array($col) || ! filled($col['key'] ?? null)) {
                continue;
            }
            $services[(string) $col['key']] = [
                'band' => $col['tone'] ?? null,
                'label' => $col['status_label'] ?? null,
                'status_reason' => $col['status_reason'] ?? null,
                'metrics' => $this->metricsMap(is_array($col['metrics'] ?? null) ? $col['metrics'] : []),
            ];
        }

        $payload = [
            'captured_at' => now()->toIso8601String(),
            'overall_band' => $bundle['overall_band'] ?? null,
            'value' => $bundle['value'] ?? null,
            'services' => $services,
        ];

        return ClientMetricDailySnapshot::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'snapshot_date' => $date->toDateString(),
            ],
            ['payload' => $payload],
        );
    }

    /**
     * @return array{
     *   available: bool,
     *   status: string,
     *   message: string,
     *   as_of: ?string,
     *   overall_band: ?string,
     *   value: ?array,
     *   services: array<string, mixed>
     * }
     */
    public function previousMonthCompare(Client $client): array
    {
        $empty = [
            'available' => false,
            'status' => 'pipeline',
            'message' => 'Last month will show here after we have a full month of figures.',
            'as_of' => null,
            'overall_band' => null,
            'value' => null,
            'services' => [],
        ];

        $targetMonth = now()->subMonthNoOverflow()->endOfMonth()->startOfDay();
        $snap = ClientMetricDailySnapshot::query()
            ->where('client_id', $client->id)
            ->where('snapshot_date', '<=', $targetMonth->toDateString())
            ->where('snapshot_date', '>=', $targetMonth->copy()->startOfMonth()->toDateString())
            ->orderByDesc('snapshot_date')
            ->first();

        if ($snap === null) {
            $snap = ClientMetricDailySnapshot::query()
                ->where('client_id', $client->id)
                ->where('snapshot_date', '<', now()->startOfMonth()->toDateString())
                ->orderByDesc('snapshot_date')
                ->first();
        }

        if ($snap === null) {
            return $empty;
        }

        $payload = is_array($snap->payload) ? $snap->payload : [];
        $asOf = $snap->snapshot_date?->toDateString();

        return [
            'available' => true,
            'status' => 'ready',
            'message' => $asOf
                ? "Snapshot from {$asOf} (end of last month / nearest prior day)."
                : 'Historical snapshot available.',
            'as_of' => $asOf,
            'overall_band' => $payload['overall_band'] ?? null,
            'value' => is_array($payload['value'] ?? null) ? $payload['value'] : null,
            'services' => is_array($payload['services'] ?? null) ? $payload['services'] : [],
        ];
    }

    /**
     * @param  list<array{label?: string, value?: string, kind?: string}>  $metrics
     * @return array<string, string>
     */
    private function metricsMap(array $metrics): array
    {
        $map = [];
        foreach ($metrics as $m) {
            if (! is_array($m) || ($m['kind'] ?? '') !== 'ok') {
                continue;
            }
            $label = (string) ($m['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $map[$label] = (string) ($m['value'] ?? '');
        }

        return $map;
    }
}
