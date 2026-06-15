<?php

namespace App\Services\SuperOps;

use App\Models\User;

class SuperOpsSsoService
{
    public function isEnabledForUser(User $user): bool
    {
        if (! $this->hasLaunchTarget()) {
            return false;
        }

        if (! $user->role->isClientFacing()) {
            return false;
        }

        return (bool) $user->client?->superops_sso_enabled;
    }

    public function configurationHint(): string
    {
        return 'Set SUPEROPS_SUBDOMAIN or SUPEROPS_REQUESTER_PORTAL_URL in .env. '
            .'Configure Entra Login URL inside SuperOps (Global SSO), not in portal .env.';
    }

    public function accessDeniedHint(User $user): string
    {
        if (! $user->role->isClientFacing()) {
            return 'SuperOps opens as a client requester. Sign in with a client account (for example portal.test@onit.ltd), not an MSP admin account.';
        }

        if (! $user->client?->superops_sso_enabled) {
            return 'SuperOps SSO is not enabled for your organisation. Contact your administrator.';
        }

        return $this->configurationHint();
    }

    private function hasLaunchTarget(): bool
    {
        return filled(config('services.superops.requester_portal_url'))
            || filled(config('services.superops.subdomain'));
    }

    public function launchUrlFor(User $user): string
    {
        $configured = config('services.superops.sso_url');

        if ($configured && ! $this->isEntraSamlEndpoint($configured)) {
            return $this->appendLoginHint($configured, $user->email);
        }

        $base = rtrim((string) config('services.superops.requester_portal_url'), '/');
        $path = config('services.superops.requester_login_path', '/#/requester/login');

        return $this->appendLoginHint($base.$path, $user->email);
    }

    private function appendLoginHint(string $url, ?string $email): string
    {
        if (! filled($email) || ! config('services.superops.login_hint_enabled', true)) {
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

    private function isEntraSamlEndpoint(string $url): bool
    {
        return str_contains($url, 'login.microsoftonline.com');
    }

    public function establishSsoSession(User $user): void
    {
        session([
            'superops_sso_ready' => true,
            'superops_sso_at' => now()->toIso8601String(),
            'superops_sso_user_id' => $user->superops_user_id,
        ]);
    }
}
