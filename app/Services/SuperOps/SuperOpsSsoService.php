<?php

namespace App\Services\SuperOps;

use App\Models\User;

class SuperOpsSsoService
{
    public function isEnabledForUser(User $user): bool
    {
        if (! config('services.superops.sso_enabled', true)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return $this->hasTechnicianLaunchTarget();
        }

        if (! $user->role->isClientFacing()) {
            return false;
        }

        if (! $this->hasRequesterLaunchTarget()) {
            return false;
        }

        return (bool) $user->client?->superops_sso_enabled;
    }

    public function configurationHint(): string
    {
        return 'Set SUPEROPS_SUBDOMAIN or SUPEROPS_REQUESTER_PORTAL_URL for clients. Technicians use the same SuperOps host with /#/technician/login. '
            .'Client requester SSO: Entra SAML in SuperOps Global SSO only.';
    }

    public function accessDeniedHint(User $user): string
    {
        if (! config('services.superops.sso_enabled', true)) {
            return 'SuperOps SSO is not enabled. Contact your administrator.';
        }

        if ($user->role->isAdmin()) {
            if (! $this->hasTechnicianLaunchTarget()) {
                return 'SuperOps technician portal is not configured. Set SUPEROPS_SUBDOMAIN or SUPEROPS_REQUESTER_PORTAL_URL in .env.';
            }
        }

        if ($user->role->isClientFacing()) {
            if (! $user->client?->superops_sso_enabled) {
                return 'SuperOps SSO is not enabled for your organisation. Contact your administrator.';
            }

            if (! $this->hasRequesterLaunchTarget()) {
                return $this->configurationHint();
            }
        }

        if (! $user->role->isAdmin() && ! $user->role->isClientFacing()) {
            return 'SuperOps access requires a team or client account.';
        }

        return $this->configurationHint();
    }

    public function launchUrlFor(User $user): string
    {
        if ($user->role->isAdmin()) {
            $base = rtrim((string) config('services.superops.technician_portal_url'), '/');
            $path = config('services.superops.technician_login_path', '/#/technician/login');

            return $this->appendLoginHint($base.$path, $user->email);
        }

        $configured = config('services.superops.sso_url');

        if ($configured && ! $this->isEntraSamlEndpoint($configured) && $this->isAllowedSuperOpsUrl($configured)) {
            return $this->appendLoginHint($configured, $user->email);
        }

        $base = rtrim((string) config('services.superops.requester_portal_url'), '/');
        $path = config('services.superops.requester_login_path', '/#/requester/login');

        return $this->appendLoginHint($base.$path, $user->email);
    }

    private function hasTechnicianLaunchTarget(): bool
    {
        return filled(config('services.superops.technician_portal_url'));
    }

    private function hasRequesterLaunchTarget(): bool
    {
        return filled(config('services.superops.requester_portal_url'))
            || filled(config('services.superops.subdomain'));
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

    private function isAllowedSuperOpsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (str_ends_with($host, '.superops.ai') || $host === 'superops.ai') {
            return true;
        }

        return in_array($host, ['portal.onit.ltd', 'app.onit.ltd'], true);
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
