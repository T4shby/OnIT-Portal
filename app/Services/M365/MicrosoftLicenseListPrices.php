<?php

namespace App\Services\M365;

/**
 * UK commercial list-price guide (ex VAT, annual commitment, monthly rate).
 *
 * This is not the customer's invoice. Pax8 discounts and bundles are not
 * included. Figures are the Microsoft UK list held in the portal as of
 * April 2025. A part number that is missing is left out of the total.
 */
class MicrosoftLicenseListPrices
{
    public const AS_OF = '2025-04';

    /**
     * Monthly pence per purchased seat.
     *
     * @var array<string, int>
     */
    private const MONTHLY_PENCE = [
        'O365_BUSINESS_ESSENTIALS' => 510,
        'SMB_BUSINESS_ESSENTIALS' => 510,
        'O365_BUSINESS' => 860,
        'SMB_BUSINESS' => 860,
        'SMB_BUSINESS_PREMIUM' => 1030,
        'SPB' => 1810,
        'O365_BUSINESS_PREMIUM' => 1810,
        'M365_BUSINESS_PREMIUM' => 1810,
        'STANDARDPACK' => 750,
        'ENTERPRISEPACK' => 2060,
        'ENTERPRISEPREMIUM' => 3510,
        'ENTERPRISEPREMIUM_NOPSTNCONF' => 3310,
        'SPE_E3' => 3310,
        'SPE_E5' => 5240,
        'SPE_F1' => 660,
        'DESKLESSPACK' => 330,
        'EXCHANGESTANDARD' => 330,
        'EXCHANGEENTERPRISE' => 660,
        'EXCHANGEARCHIVE_ADDON' => 250,
        'EXCHANGEDESKLESS' => 170,
        'SHAREPOINTSTANDARD' => 420,
        'SHAREPOINTENTERPRISE' => 840,
        'AAD_PREMIUM' => 500,
        'AAD_PREMIUM_P2' => 750,
        'ATP_ENTERPRISE' => 170,
        'EMS' => 870,
        'EMSPREMIUM' => 1400,
        'IDENTITY_THREAT_PROTECTION' => 990,
        'INFORMATION_PROTECTION_COMPLIANCE' => 990,
        'INTUNE_A' => 660,
        'POWER_BI_PRO' => 1120,
        'PBI_PREMIUM_PER_USER' => 1680,
        'FLOW_PER_USER' => 1240,
        'FLOW_PER_USER_V2' => 1240,
        'POWERAPPS_PER_USER' => 1660,
        'VISIOONLINE_PLAN1' => 420,
        'VISIOCLIENT' => 1260,
        'PROJECTESSENTIALS' => 840,
        'PROJECTPROFESSIONAL' => 2530,
        'PROJECTPREMIUM' => 4600,
        'MCOEV' => 660,
        'Microsoft_Teams_Premium' => 870,
        'MCOMEETADV' => 330,
        'WIN10_PRO_ENT_SUB' => 600,
        'WIN10_VDA_E3' => 600,
        'WIN10_VDA_E5' => 990,
    ];

    /**
     * @param  list<array<string, mixed>>  $skus
     * @return array{monthly_pence: int, annual_pence: int, unpriced: int, priced_seats: int}
     */
    public function summarise(array $skus): array
    {
        $monthly = 0;
        $unpriced = 0;
        $seats = 0;

        foreach ($skus as $sku) {
            if (! is_array($sku) || ! $this->isBillable($sku)) {
                continue;
            }

            $purchased = max(0, (int) ($sku['purchased'] ?? 0));
            if ($purchased === 0) {
                continue;
            }

            $pence = $this->monthlyPence((string) ($sku['skuPartNumber'] ?? ''));
            if ($pence === null) {
                $unpriced++;

                continue;
            }

            $monthly += $pence * $purchased;
            $seats += $purchased;
        }

        return [
            'monthly_pence' => $monthly,
            'annual_pence' => $monthly * 12,
            'unpriced' => $unpriced,
            'priced_seats' => $seats,
        ];
    }

    /**
     * @param  array<string, mixed>  $sku
     * @return array{monthly_pence: ?int, line_pence: ?int}
     */
    public function quote(array $sku): array
    {
        if (! $this->isBillable($sku)) {
            return ['monthly_pence' => null, 'line_pence' => null];
        }

        $purchased = max(0, (int) ($sku['purchased'] ?? 0));
        $pence = $this->monthlyPence((string) ($sku['skuPartNumber'] ?? ''));
        if ($pence === null || $purchased === 0) {
            return ['monthly_pence' => $pence, 'line_pence' => null];
        }

        return [
            'monthly_pence' => $pence,
            'line_pence' => $pence * $purchased,
        ];
    }

    public function formatPence(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }

    public function monthlyPence(string $skuPartNumber): ?int
    {
        return self::MONTHLY_PENCE[$skuPartNumber] ?? null;
    }

    /**
     * @param  array<string, mixed>  $sku
     */
    private function isBillable(array $sku): bool
    {
        if (array_key_exists('countsTowardUtilisation', $sku)) {
            return (bool) $sku['countsTowardUtilisation'];
        }

        return MicrosoftLicenseSkuNames::countsTowardOverallUtilisation(
            (string) ($sku['skuPartNumber'] ?? ''),
            (int) ($sku['purchased'] ?? 0),
            (int) ($sku['assigned'] ?? 0),
        );
    }
}
