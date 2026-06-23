<?php

namespace App\Enums;

enum M365GroupType: string
{
    case SecurityGroup = 'security_group';
    case DistributionList = 'distribution_list';
    case Microsoft365Group = 'microsoft_365_group';
    case MailEnabledSecurityGroup = 'mail_enabled_security_group';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SecurityGroup => 'Security group',
            self::DistributionList => 'Distribution list',
            self::Microsoft365Group => 'Microsoft 365 group',
            self::MailEnabledSecurityGroup => 'Mail-enabled security group',
            self::Other => 'Group',
        };
    }

    /**
     * @param  array{groupTypes?: list<string>, mailEnabled?: bool, securityEnabled?: bool}  $group
     */
    public static function classify(array $group): self
    {
        $groupTypes = $group['groupTypes'] ?? [];
        $mailEnabled = (bool) ($group['mailEnabled'] ?? false);
        $securityEnabled = (bool) ($group['securityEnabled'] ?? false);

        if (in_array('Unified', $groupTypes, true)) {
            return self::Microsoft365Group;
        }

        if ($mailEnabled && $securityEnabled) {
            return self::MailEnabledSecurityGroup;
        }

        if ($mailEnabled) {
            return self::DistributionList;
        }

        if ($securityEnabled) {
            return self::SecurityGroup;
        }

        return self::Other;
    }
}
