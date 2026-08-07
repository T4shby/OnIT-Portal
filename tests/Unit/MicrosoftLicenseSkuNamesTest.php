<?php

namespace Tests\Unit;

use App\Services\M365\MicrosoftLicenseSkuNames;
use PHPUnit\Framework\TestCase;

class MicrosoftLicenseSkuNamesTest extends TestCase
{
    public function test_known_skus_have_friendly_names(): void
    {
        $this->assertSame('Microsoft 365 Business Premium', MicrosoftLicenseSkuNames::displayName('SPB'));
        $this->assertSame('Microsoft 365 Business Premium', MicrosoftLicenseSkuNames::displayName('O365_BUSINESS_PREMIUM'));
        $this->assertSame('Exchange Online (Plan 2)', MicrosoftLicenseSkuNames::displayName('EXCHANGEENTERPRISE'));
        $this->assertSame('Power Automate Free', MicrosoftLicenseSkuNames::displayName('FLOW_FREE'));
    }

    public function test_unknown_skus_are_humanized(): void
    {
        $this->assertSame('Some Future SKU', MicrosoftLicenseSkuNames::displayName('SOME_FUTURE_SKU'));
    }

    public function test_bulk_free_and_trial_skus_excluded_from_utilisation(): void
    {
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('FLOW_FREE', 1_000_000));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('SPB', 200_000));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('ANY_SKU', 10_000));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('PROJECT_MADEIRA_PREVIEW_IW_SKU', 10_000));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('SOME_PREVIEW_SKU', 500));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('POWERPAGES_TRIAL_FOR_MAKERS', 10_000));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('Something_With_Trial_Inside', 50));
        $this->assertFalse(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('HUGEQUOTA', 2_000, 3));
        $this->assertTrue(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('SPB', 21));
        $this->assertTrue(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('EXCHANGEENTERPRISE', 10));
        $this->assertTrue(MicrosoftLicenseSkuNames::countsTowardOverallUtilisation('SPB', 1_200, 900));
    }

    public function test_madeira_preview_has_friendly_name(): void
    {
        $this->assertSame(
            'Dynamics 365 Business Central for IWs',
            MicrosoftLicenseSkuNames::displayName('PROJECT_MADEIRA_PREVIEW_IW_SKU'),
        );
    }
}
