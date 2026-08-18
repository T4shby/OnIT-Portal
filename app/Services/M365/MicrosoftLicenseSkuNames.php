<?php

namespace App\Services\M365;

/**
 * Maps Graph subscribedSku skuPartNumber values to customer-friendly product names,
 * and flags free/bulk SKUs that must not skew "overall utilisation".
 *
 * Free / trial detection is heuristic (patterns + large prepaid pools) so new Microsoft
 * giveaway SKUs do not need a hard-coded list each time.
 */
class MicrosoftLicenseSkuNames
{
    /**
     * Prepaid seat pools at or above this are treated as free/unmetered Microsoft offers
     * (Power Pages maker trials, Business Central IW, FLOW_FREE millions, …).
     * Paid MSP seat buys almost never sit at 10k+ unconsumed capacity.
     */
    public const BULK_FREE_PREPAID_THRESHOLD = 10_000;

    /**
     * Common MSP / commercial SKU part numbers → portal labels (Microsoft marketing names).
     *
     * @var array<string, string>
     */
    private const DISPLAY_NAMES = [
        'SPB' => 'Microsoft 365 Business Premium',
        'O365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Premium',
        'M365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Premium',
        'SMB_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS' => 'Microsoft 365 Apps for business',
        'SMB_BUSINESS' => 'Microsoft 365 Apps for business',
        'SMB_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'SPE_E3' => 'Microsoft 365 E3',
        'SPE_E5' => 'Microsoft 365 E5',
        'SPE_F1' => 'Microsoft 365 F3',
        'ENTERPRISEPACK' => 'Office 365 E3',
        'ENTERPRISEPREMIUM' => 'Office 365 E5',
        'ENTERPRISEPREMIUM_NOPSTNCONF' => 'Office 365 E5 (no Audio Conferencing)',
        'STANDARDPACK' => 'Office 365 E1',
        'DESKLESSPACK' => 'Office 365 F3',
        'EXCHANGESTANDARD' => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE' => 'Exchange Online (Plan 2)',
        'EXCHANGEARCHIVE_ADDON' => 'Exchange Online Archiving',
        'EXCHANGEESSENTIALS' => 'Exchange Online Essentials',
        'EXCHANGEDESKLESS' => 'Exchange Online Kiosk',
        'SHAREPOINTSTANDARD' => 'SharePoint Online (Plan 1)',
        'SHAREPOINTENTERPRISE' => 'SharePoint Online (Plan 2)',
        'PROJECTPREMIUM' => 'Project Plan 5',
        'PROJECTPROFESSIONAL' => 'Project Plan 3',
        'PROJECTESSENTIALS' => 'Project Plan 1',
        'VISIOCLIENT' => 'Visio Plan 2',
        'VISIOONLINE_PLAN1' => 'Visio Plan 1',
        'POWER_BI_PRO' => 'Power BI Pro',
        'POWER_BI_STANDARD' => 'Power BI Free',
        'PBI_PREMIUM_PER_USER' => 'Power BI Premium Per User',
        'FLOW_FREE' => 'Power Automate Free',
        'FLOW_PER_USER' => 'Power Automate Per User',
        'FLOW_PER_USER_V2' => 'Power Automate Per User',
        'POWERAPPS_VIRAL' => 'Power Apps Trial',
        'POWERAPPS_PER_USER' => 'Power Apps Per User',
        'POWERAPPS_DEV' => 'Power Apps Developer Plan',
        'Microsoft_Teams_Premium' => 'Microsoft Teams Premium',
        'TEAMS_EXPLORATORY' => 'Microsoft Teams Exploratory',
        'MCOEV' => 'Microsoft Teams Phone',
        'MCOSTANDARD' => 'Skype for Business Online (Plan 2)',
        'MCOMEETADV' => 'Microsoft 365 Audio Conferencing',
        'PHONESYSTEM_VIRTUALUSER' => 'Teams Phone Resource Account',
        'INTUNE_A' => 'Microsoft Intune Plan 1',
        'WIN10_PRO_ENT_SUB' => 'Windows 10/11 Enterprise E3',
        'WIN10_VDA_E3' => 'Windows 10/11 Enterprise E3',
        'WIN10_VDA_E5' => 'Windows 10/11 Enterprise E5',
        'IDENTITY_THREAT_PROTECTION' => 'Microsoft 365 E5 Security',
        'INFORMATION_PROTECTION_COMPLIANCE' => 'Microsoft 365 E5 Compliance',
        'EMSPREMIUM' => 'Enterprise Mobility + Security E5',
        'EMS' => 'Enterprise Mobility + Security E3',
        'AAD_PREMIUM' => 'Microsoft Entra ID P1',
        'AAD_PREMIUM_P2' => 'Microsoft Entra ID P2',
        'ATP_ENTERPRISE' => 'Microsoft Defender for Office 365 (Plan 1)',
        'THREAT_INTELLIGENCE' => 'Microsoft Defender for Office 365 (Plan 2)',
        'DEFENDER_ENDPOINT_P1' => 'Microsoft Defender for Endpoint P1',
        'DEFENDER_ENDPOINT_P2' => 'Microsoft Defender for Endpoint P2',
        'STREAM' => 'Microsoft Stream',
        'STREAM_P2' => 'Microsoft Stream Plan 2',
        'POWERAPPS_PER_APP_IW' => 'Power Apps per app',
        'DYN365_ENTERPRISE_PLAN1' => 'Dynamics 365 Customer Engagement Plan',
        'DYN365_BUSINESS_CENTRAL_ESSENTIALS' => 'Dynamics 365 Business Central Essentials',
        'DYN365_BUSINESS_CENTRAL_PREMIUM' => 'Dynamics 365 Business Central Premium',
        'O365_BUSINESS_ESSENTIALS_GOV' => 'Microsoft 365 Business Basic (GCC)',
        'RIGHTSMANAGEMENT_ADHOC' => 'Azure Rights Management (Ad Hoc)',
        'WINDOWS_STORE' => 'Windows Store for Business',
        'CCIBOTS_PRIVPREV_VIRAL' => 'Power Virtual Agents Viral Trial',
        'Microsoft_365_Copilot' => 'Microsoft 365 Copilot',
        'Microsoft_365_Copilot_for_Business' => 'Microsoft 365 Copilot',
        'BUSINESS_PREMIUM_AND_MICROSOFT_365_COPILOT_FOR_BUSINESS' => 'Business Premium + Copilot',
        'Microsoft_365_F3' => 'Microsoft 365 F3',
        'Microsoft_365_E3' => 'Microsoft 365 E3',
        'Microsoft_365_E5' => 'Microsoft 365 E5',
        'Microsoft_365_Business_Premium' => 'Microsoft 365 Business Premium',
        'Microsoft_365_Business_Standard' => 'Microsoft 365 Business Standard',
        'Microsoft_365_Business_Basic' => 'Microsoft 365 Business Basic',
    ];

    /**
     * Optional exact free SKUs (fast path). Prefer name/bulk heuristics for new SKUs.
     *
     * @var list<string>
     */
    private const EXCLUDED_EXACT = [
        'FLOW_FREE',
        'POWER_BI_STANDARD',
        'POWERAPPS_VIRAL',
        'POWERAPPS_DEV',
        'TEAMS_EXPLORATORY',
        'RIGHTSMANAGEMENT_ADHOC',
        'WINDOWS_STORE',
        'CCIBOTS_PRIVPREV_VIRAL',
        'Microsoft_Teams_Exploratory_Dept',
        'PROJECT_MADEIRA_PREVIEW_IW_SKU',
        'PROJECTMADEIRA_PREVIEW',
    ];

    /**
     * Substrings in skuPartNumber (case-insensitive) that mark free, trial, or non-billable seats.
     *
     * @var list<string>
     */
    private const EXCLUDED_NAME_FRAGMENTS = [
        'FREE',
        'TRIAL',
        'VIRAL',
        'EXPLORATORY',
        'DEVELOPER',
        'PREVIEW',
        'MADEIRA',
        'STUDENT',
        'FOR_MAKERS',
        'MAKER',
        'POWERPAGE',
        'POWER_PAGE',
        'POWERPAGES',
        'RIGHTSMANAGEMENT_ADHOC',
        'WINDOWS_STORE',
        '_IW_SKU',
        '_IW',
    ];

    public static function displayName(string $skuPartNumber): string
    {
        $key = trim($skuPartNumber);

        if ($key === '') {
            return 'Unknown licence';
        }

        if (isset(self::DISPLAY_NAMES[$key])) {
            return self::DISPLAY_NAMES[$key];
        }

        foreach (self::DISPLAY_NAMES as $part => $name) {
            if (strcasecmp($part, $key) === 0) {
                return $name;
            }
        }

        if (stripos($key, 'MADEIRA') !== false || strcasecmp($key, 'PROJECT_MADEIRA_PREVIEW_IW_SKU') === 0) {
            return 'Dynamics 365 Business Central for IWs';
        }

        if (stripos($key, 'POWERPAGE') !== false || stripos($key, 'POWER_PAGE') !== false) {
            return 'Power Pages Trial for Makers';
        }

        return self::humanizePartNumber($key);
    }

    /**
     * @param  list<string>|array<int, string>  $skuPartNumbers
     * @return list<string>
     */
    public static function displayNames(array $skuPartNumbers): array
    {
        return array_column(self::labelledSkus($skuPartNumbers), 'label');
    }

    /**
     * @param  list<string>|array<int, string>  $skuPartNumbers
     * @return list<array{sku: string, label: string}>
     */
    public static function labelledSkus(array $skuPartNumbers): array
    {
        $rows = [];
        $seen = [];
        foreach ($skuPartNumbers as $sku) {
            $part = (string) $sku;
            $label = self::displayName($part);
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $rows[] = ['sku' => $part, 'label' => $label];
        }

        return $rows;
    }

    /**
     * Whether this inventory row should contribute to seats purchased/assigned overall utilisation.
     * Free/trial/preview/bulk-capacity Microsoft SKUs are excluded so free seat pools do not report ~0%.
     */
    public static function countsTowardOverallUtilisation(
        string $skuPartNumber,
        int $prepaidEnabled,
        ?int $consumedUnits = null,
    ): bool {
        if ($prepaidEnabled <= 0) {
            return false;
        }

        if ($prepaidEnabled >= self::BULK_FREE_PREPAID_THRESHOLD) {
            return false;
        }

        foreach (self::EXCLUDED_EXACT as $excluded) {
            if (strcasecmp($excluded, $skuPartNumber) === 0) {
                return false;
            }
        }

        if (self::partNumberLooksNonBillable($skuPartNumber)) {
            return false;
        }

        // Sparse use of a large prepaid pool (typical Microsoft giveaway, not a paid MSP buy).
        if ($consumedUnits !== null
            && $prepaidEnabled >= 1_000
            && $consumedUnits <= max(5, (int) floor($prepaidEnabled * 0.02))) {
            return false;
        }

        return true;
    }

    public static function partNumberLooksNonBillable(string $skuPartNumber): bool
    {
        $key = strtoupper(str_replace(['-', ' '], '_', trim($skuPartNumber)));

        if ($key === '') {
            return false;
        }

        foreach (self::EXCLUDED_NAME_FRAGMENTS as $fragment) {
            $needle = strtoupper(str_replace(['-', ' '], '_', $fragment));
            if ($needle === '') {
                continue;
            }
            // Avoid matching ordinary product names that merely end with "E" then something —
            // fragments are deliberate free/trial keywords.
            if (str_contains($key, $needle)) {
                // "_IW" alone is short — require end or _IW_ form.
                if ($needle === '_IW') {
                    if (str_ends_with($key, '_IW') || str_contains($key, '_IW_')) {
                        return true;
                    }

                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private static function humanizePartNumber(string $skuPartNumber): string
    {
        $label = str_replace(['_', '-'], ' ', $skuPartNumber);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        $label = trim($label);

        $words = array_map(static function (string $word): string {
            $upper = strtoupper($word);
            if (in_array($upper, ['M365', 'O365', 'EMS', 'AAD', 'SKU', 'E1', 'E3', 'E5', 'F1', 'F3', 'P1', 'P2'], true)) {
                return $upper;
            }

            return ucfirst(strtolower($word));
        }, explode(' ', $label));

        return implode(' ', $words);
    }
}
