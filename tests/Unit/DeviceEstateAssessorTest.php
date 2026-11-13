<?php

namespace Tests\Unit;

use App\Services\SuperOps\DeviceEstateAssessor;
use Carbon\Carbon;
use Tests\TestCase;

class DeviceEstateAssessorTest extends TestCase
{
    public function test_purchase_date_beats_warranty_and_cpu(): void
    {
        $now = Carbon::parse('2026-11-13');
        $estimate = app(DeviceEstateAssessor::class)->estimateDate(
            '2024-01-15',
            '2027-01-15',
            '2025-06-01',
            'Intel Core i5-8400',
            $now,
        );

        $this->assertSame('purchase', $estimate['source']);
        $this->assertSame('under_3', app(DeviceEstateAssessor::class)->ageBand($estimate['at'], $now));
    }

    public function test_warranty_expiry_estimates_a_three_year_term(): void
    {
        $now = Carbon::parse('2026-11-13');
        $estimate = app(DeviceEstateAssessor::class)->estimateDate(
            null,
            '2025-01-01',
            null,
            null,
            $now,
        );

        $this->assertSame('warranty', $estimate['source']);
        $this->assertSame('2022-01-01', $estimate['at']?->toDateString());
        $this->assertSame('3_to_5', app(DeviceEstateAssessor::class)->ageBand($estimate['at'], $now));
    }

    public function test_old_cpu_is_only_used_when_no_date_exists(): void
    {
        $now = Carbon::parse('2026-11-13');
        $estimate = app(DeviceEstateAssessor::class)->estimateDate(
            null,
            null,
            null,
            'Intel(R) Core(TM) i5-8400 CPU @ 2.80GHz',
            $now,
        );

        $this->assertSame('cpu', $estimate['source']);
        $this->assertSame('over_5', app(DeviceEstateAssessor::class)->ageBand($estimate['at'], $now));
    }

    public function test_windows_install_is_used_before_the_cpu(): void
    {
        $now = Carbon::parse('2026-11-13');
        $estimate = app(DeviceEstateAssessor::class)->estimateDate(
            null,
            null,
            '2022-03-01',
            'Intel Core i7-12700',
            $now,
        );

        $this->assertSame('os_install', $estimate['source']);
        $this->assertSame('3_to_5', app(DeviceEstateAssessor::class)->ageBand($estimate['at'], $now));
    }

    public function test_workstation_standard_flags_i3_and_low_ram(): void
    {
        $assessor = app(DeviceEstateAssessor::class);

        $below = $assessor->assessWorkstation('Intel Core i3-10100', 8.0);
        $this->assertTrue($below['below']);
        $this->assertFalse($below['meets']);
        $this->assertSame('Intel Core i3-10100, 8 GB RAM', $assessor->gapLabel($below, 'Intel Core i3-10100', 8.0));

        $meets = $assessor->assessWorkstation('Intel Core i5-12400', 15.8);
        $this->assertTrue($meets['meets']);
        $this->assertFalse($meets['below']);

        $ryzen = $assessor->assessWorkstation('AMD Ryzen 5 5600', 16.0);
        $this->assertTrue($ryzen['meets']);

        $unknown = $assessor->assessWorkstation('', null);
        $this->assertFalse($unknown['below']);
        $this->assertFalse($unknown['meets']);
    }
}
