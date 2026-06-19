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

        return filled($user->client?->pax8_company_id);
    }

    public function configurationHint(): string
    {
        return 'Set PAX8_PARTNER_PORTAL_URL and PAX8_COMPANY_URL_TEMPLATE in .env. '
            .'Configure Pax8 Enterprise SSO (Azure AD) in Pax8 Admin → My Partner Profile.';
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
            $base = rtrim((string) config('services.pax8.partner_url'), '/');
            $path = (string) config('services.pax8.partner_login_path', '/login');

            return $this->appendLoginHint($base.$path, $user->email);
        }

        $companyId = $user->client->pax8_company_id;
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
}
