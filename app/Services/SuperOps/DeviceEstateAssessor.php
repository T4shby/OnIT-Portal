<?php

namespace App\Services\SuperOps;

use Carbon\Carbon;

/**
 * Turns SuperOps asset fields into an age band and a workstation-standard check.
 *
 * Age prefers a recorded purchase date. Warranty expiry is then treated as a
 * 3-year (or 1-year) warranty. Windows install date and CPU generation are
 * weaker indicators: a reimage or an old CPU in a newer chassis can mislead.
 *
 * The workstation standard is a Core i5 / Ryzen 5 class processor and 16 GB
 * RAM. SuperOps does not report the graphics adapter, so a missing GPU is
 * not a failure.
 */
class DeviceEstateAssessor
{
    /**
     * @return array{at: ?Carbon, source: string}
     */
    public function estimateDate(
        ?string $purchased,
        ?string $warrantyExpiry,
        ?string $osInstalled,
        ?string $cpuName,
        ?Carbon $now = null,
    ): array {
        $now = $now ?? now();

        $purchase = $this->usablePastDate($purchased, $now, 30);
        if ($purchase !== null) {
            return ['at' => $purchase, 'source' => 'purchase'];
        }

        $expiry = $this->parse($warrantyExpiry);
        if ($expiry !== null
            && $expiry->lte($now->copy()->addYears(4))
            && $expiry->gte($now->copy()->subYears(15))
        ) {
            $fromThree = $expiry->copy()->subYears(3);
            if ($fromThree->lte($now)) {
                return ['at' => $fromThree, 'source' => 'warranty'];
            }

            $fromOne = $expiry->copy()->subYear();
            if ($fromOne->lte($now)) {
                return ['at' => $fromOne, 'source' => 'warranty'];
            }
        }

        $installed = $this->usablePastDate($osInstalled, $now, 20);
        if ($installed !== null) {
            return ['at' => $installed, 'source' => 'os_install'];
        }

        $year = $this->cpuLaunchYear($cpuName);
        if ($year !== null) {
            $at = Carbon::create($year, 6, 1, 0, 0, 0, $now->timezone);
            if ($at instanceof Carbon && $at->lte($now)) {
                return ['at' => $at, 'source' => 'cpu'];
            }
        }

        return ['at' => null, 'source' => 'unknown'];
    }

    public function ageBand(?Carbon $at, ?Carbon $now = null): string
    {
        if ($at === null) {
            return 'unknown';
        }

        $now = $now ?? now();
        if ($at->greaterThan($now)) {
            return 'unknown';
        }

        $years = $at->diffInYears($now);
        if ($years < 3) {
            return 'under_3';
        }

        if ($years < 5) {
            return '3_to_5';
        }

        return 'over_5';
    }

    /**
     * @return array{cpu: ?bool, ram: ?bool, meets: bool, below: bool, gaps: list<string>}
     */
    public function assessWorkstation(?string $cpuName, ?float $ramGb): array
    {
        $cpu = $this->cpuMeetsStandard($cpuName);
        $ram = $ramGb === null ? null : $ramGb >= 14.5;
        $gaps = [];

        if ($cpu === false) {
            $gaps[] = 'cpu';
        }

        if ($ram === false) {
            $gaps[] = 'ram';
        }

        return [
            'cpu' => $cpu,
            'ram' => $ram,
            'meets' => $cpu === true && $ram === true,
            'below' => $gaps !== [],
            'gaps' => $gaps,
        ];
    }

    public function gapLabel(array $assessment, ?string $cpuName, ?float $ramGb): string
    {
        $parts = [];

        if (in_array('cpu', $assessment['gaps'] ?? [], true)) {
            $name = trim((string) $cpuName);
            $parts[] = $name !== '' ? $name : 'processor below Core i5';
        }

        if (in_array('ram', $assessment['gaps'] ?? [], true)) {
            $parts[] = $ramGb === null
                ? 'under 16 GB RAM'
                : rtrim(rtrim(number_format($ramGb, 1), '0'), '.').' GB RAM';
        }

        return implode(', ', $parts);
    }

    public function cpuLaunchYear(?string $cpuName): ?int
    {
        $name = trim((string) $cpuName);
        if ($name === '') {
            return null;
        }

        if (preg_match('/core\s+ultra\s+[579]\s*(\d)/i', $name, $ultra)) {
            return ((int) $ultra[1]) >= 2 ? 2024 : 2023;
        }

        if (preg_match('/\bi[3579]\s*-\s*(\d{4,5})\b/i', $name, $intel)) {
            $model = $intel[1];
            $generation = strlen($model) >= 5
                ? (int) substr($model, 0, 2)
                : (int) $model[0];

            return match (true) {
                $generation <= 6 => 2015,
                $generation === 7 => 2016,
                $generation === 8 => 2017,
                $generation === 9 => 2018,
                $generation === 10 => 2019,
                $generation === 11 => 2020,
                $generation === 12 => 2021,
                $generation === 13 => 2022,
                $generation === 14 => 2023,
                default => 2024,
            };
        }

        if (preg_match('/ryzen\s+[3579]\s+(\d)/i', $name, $ryzen)) {
            return match ((int) $ryzen[1]) {
                1 => 2017,
                2 => 2018,
                3 => 2019,
                4 => 2020,
                5 => 2020,
                6 => 2022,
                7 => 2022,
                8 => 2024,
                default => 2024,
            };
        }

        if (preg_match('/\bapple\s+m([1-4])\b/i', $name, $apple)) {
            return match ((int) $apple[1]) {
                1 => 2020,
                2 => 2022,
                3 => 2023,
                default => 2024,
            };
        }

        return null;
    }

    private function cpuMeetsStandard(?string $cpuName): ?bool
    {
        $name = strtolower(trim((string) $cpuName));
        if ($name === '') {
            return null;
        }

        if (preg_match('/\b(celeron|pentium|atom)\b/', $name)) {
            return false;
        }

        if (preg_match('/\bi3\b/', $name) || preg_match('/ryzen\s+3\b/', $name)) {
            return false;
        }

        if (preg_match('/core\s+ultra\s+[579]\b/', $name)) {
            return true;
        }

        if (preg_match('/\bi[579]\b/', $name) || preg_match('/ryzen\s+[579]\b/', $name)) {
            return true;
        }

        if (preg_match('/\b(xeon|apple\s+m[1-4])\b/', $name)) {
            return true;
        }

        return null;
    }

    private function usablePastDate(?string $value, Carbon $now, int $maxYears): ?Carbon
    {
        $parsed = $this->parse($value);
        if ($parsed === null || $parsed->greaterThan($now)) {
            return null;
        }

        if ($parsed->lt($now->copy()->subYears($maxYears))) {
            return null;
        }

        return $parsed;
    }

    private function parse(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
