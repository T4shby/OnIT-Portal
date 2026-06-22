<?php

namespace App\Services\Pax8;

use App\Models\User;

class Pax8SsoService
{
    public function isEnabledForUser(User $user): bool
    {
        if (! config('services.pax8.enabled', true)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return filled(config('services.pax8.partner_url'));
        }

        if (! $user->role->isClientFacing()) {
            return false;
        }

        if (! filled(config('services.pax8.company_url_template'))) {
            return false;
        }

        return filled($user->client?->pax8_company_id)
            && preg_match('/^[A-Za-z0-9_-]+$/', (string) $user->client->pax8_company_id);
    }

    public function configurationHint(): string
    {
        return 'Set PAX8_PARTNER_PORTAL_URL=https://app.pax8.com and PAX8_COMPANY_URL_TEMPLATE in .env. '
            .'Technician Microsoft SSO requires Pax8 Enterprise SSO (Primary Partner Admin → My Partner Profile → Enterprise SSO). '
            .'See Brain/Pax8EnterpriseSsoSetup.md.';
    }

    public function accessDeniedHint(User $user): string
    {
        if (! config('services.pax8.enabled', true)) {
            return 'Pax8 SSO is not enabled. Contact your administrator.';
        }

        if ($user->role->isAdmin()) {
            if (! filled(config('services.pax8.partner_url'))) {
                return $this->configurationHint();
            }
        }

        if ($user->role->isClientFacing()) {
            if (! filled($user->client?->pax8_company_id)) {
                return 'Pax8 is not linked to your organisation yet. Ask your administrator to set the Pax8 company ID on your client record.';
            }

            if (! filled(config('services.pax8.company_url_template'))) {
                return $this->configurationHint();
            }
        }

        if (! $user->role->isAdmin() && ! $user->role->isClientFacing()) {
            return 'Pax8 access requires a team or client account.';
        }

        return $this->configurationHint();
    }

    public function launchUrlFor(User $user): string
    {
        if ($user->role->isAdmin()) {
            $base = $this->partnerLaunchBase();
            $path = (string) config('services.pax8.partner_login_path', '/login');

            return $this->appendLoginHint($base.$path, $user->email);
        }

        $companyId = (string) $user->client->pax8_company_id;
        $url = str_replace(
            '{companyId}',
            $companyId,
            (string) config('services.pax8.company_url_template'),
        );

        return $this->appendLoginHint($url, $user->email);
    }

    private function appendLoginHint(string $url, ?string $email): string
    {
        if (! filled($email) || ! config('services.pax8.login_hint_enabled', true)) {
            return $url;
        }

        $hint = 'login_hint='.rawurlencode($email);

        if (str_contains($url, '#')) {
            [$before, $fragment] = explode('#', $url, 2);
            $separator = str_contains($before, '?') ? '&' : '?';

            return $before.$separator.$hint.'#'.$fragment;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.$hint;
    }

    /**
     * Pax8 Enterprise SSO only works at app.pax8.com — not mycommandconsole.com.
     *
     * @see Brain/Pax8EnterpriseSsoSetup.md
     */
    private function partnerLaunchBase(): string
    {
        $configured = rtrim((string) config('services.pax8.partner_url'), '/');

        if ($this->isEnterpriseSsoCompatiblePartnerUrl($configured)) {
            return $configured;
        }

        return 'https://app.pax8.com';
    }

    private function isEnterpriseSsoCompatiblePartnerUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return strtolower($host) === 'app.pax8.com';
    }
}
