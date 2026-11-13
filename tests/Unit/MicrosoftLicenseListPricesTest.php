<?php

namespace Tests\Unit;

use App\Services\M365\MicrosoftLicenseListPrices;
use Tests\TestCase;

class MicrosoftLicenseListPricesTest extends TestCase
{
    public function test_sums_purchased_seats_and_skips_free_and_unknown_skus(): void
    {
        $summary = app(MicrosoftLicenseListPrices::class)->summarise([
            [
                'skuPartNumber' => 'SPB',
                'purchased' => 10,
                'assigned' => 8,
                'countsTowardUtilisation' => true,
            ],
            [
                'skuPartNumber' => 'FLOW_FREE',
                'purchased' => 10000,
                'assigned' => 3,
                'countsTowardUtilisation' => false,
            ],
            [
                'skuPartNumber' => 'MADE_UP_SKU',
                'purchased' => 2,
                'assigned' => 2,
                'countsTowardUtilisation' => true,
            ],
        ]);

        $this->assertSame(1810 * 10, $summary['monthly_pence']);
        $this->assertSame(1810 * 10 * 12, $summary['annual_pence']);
        $this->assertSame(1, $summary['unpriced']);
        $this->assertSame(10, $summary['priced_seats']);
        $this->assertSame('£181.00', app(MicrosoftLicenseListPrices::class)->formatPence($summary['monthly_pence']));
    }
}
