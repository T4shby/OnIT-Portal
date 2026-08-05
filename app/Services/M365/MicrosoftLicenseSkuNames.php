<?php

namespace App\Services\M365;

/**
 * Maps Graph subscribedSku skuPartNumber values to customer-friendly product names,
 * and flags free/bulk SKUs that must not skew "overall utilisation".
 */
class MicrosoftLicenseSkuNames
{
    /**
     * Prepaid seat pools at or above this are almost always free/unmetered Microsoft offers
     * (e.g. FLOW_FREE with 1,000,000 seats), not paid licenses.
     */
    public const BULK_FREE_PREPAID_THRESHOLD = 100_000;

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
        'Microsoft_365_F3' => 'Microsoft 365 F3',
        'Microsoft_365_E3' => 'Microsoft 365 E3',
        'Microsoft_365_E5' => 'Microsoft 365 E5',
        'Microsoft_365_Business_Premium' => 'Microsoft 365 Business Premium',
        'Microsoft_365_Business_Standard' => 'Microsoft 365 Business Standard',
        'Microsoft_365_Business_Basic' => 'Microsoft 365 Business Basic',
    ];

    /**
     * SKU part numbers (exact) that are free, trial, or unmetered and never count toward paid utilisation.
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

        // Case-insensitive exact match (Graph casing varies on newer SKUs).
        foreach (self::DISPLAY_NAMES as $part => $name) {
            if (strcasecmp($part, $key) === 0) {
                return $name;
            }
        }

        return self::humanizePartNumber($key);
    }

    /**
     * Whether this inventory row should contribute to seats purchased/assigned overall utilisation.
     * Free/trial/bulk-capacity Microsoft SKUs are excluded so 1e6 free seats do not report 0%.
     */
    public static function countsTowardOverallUtilisation(string $skuPartNumber, int $prepaidEnabled): bool
    {
        if ($prepaidEnabled <= 0) {
            return false;
        }

        if ($prepaidEnabled >= self::BULK_FREE_PREPAID_THRESHOLD) {
            return false;
        }

        $key = strtoupper(trim($skuPartNumber));

        foreach (self::EXCLUDED_EXACT as $excluded) {
            if (strcasecmp($excluded, $skuPartNumber) === 0) {
                return false;
            }
        }

        if (str_ends_with($key, '_FREE')
            || str_contains($key, '_FREE_')
            || str_ends_with($key, '_TRIAL')
            || str_contains($key, '_TRIAL_')
            || str_contains($key, '_VIRAL')
            || str_contains($key, 'EXPLORATORY')
            || str_contains($key, 'DEVELOPER')) {
            return false;
        }

        return true;
    }

    private static function humanizePartNumber(string $skuPartNumber): string
    {
        $label = str_replace(['_', '-'], ' ', $skuPartNumber);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        $label = trim($label);

        // Preserve common tokens while title-casing the rest.
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
