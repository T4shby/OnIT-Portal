<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\BootstrapClientEntraJob;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\SuperOps\SuperOpsSsoService;
use App\Services\SuperOps\SuperOpsUserSyncService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class MicrosoftAuthController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
        private SuperOpsUserSyncService $superOpsSync,
        private SuperOpsSsoService $superOpsSso,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! config('services.azure.client_id') || ! config('services.azure.client_secret')) {
            return redirect()->route('login')
                ->with('error', 'Microsoft sign-in is not configured. Add MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET to your .env file.');
        }

        $request->session()->save();

        Log::info('Microsoft OAuth redirect started', $this->oauthDiagnostics($request));

        return $this->azureDriver()->redirect();
    }

    public function callback(Request $request): RedirectResponse|View
    {
        if ($request->query('admin_consent') === 'True') {
            return $this->handleAdminConsentReturn($request);
        }

        if ($request->filled('error')) {
            $description = $request->string('error_description')->before('Trace ID')->trim();

            Log::warning('Microsoft OAuth error redirect', [
                ...$this->oauthDiagnostics($request),
                'full_url' => $request->fullUrl(),
                'microsoft_error' => $request->input('error'),
                'description' => $description,
            ]);

            return redirect()->route('login')
                ->with('error', $this->publicOAuthErrorMessage(
                    new \RuntimeException('Microsoft sign-in was rejected: '.$description)
                ));
        }

        if (! $request->filled('code')) {
            Log::warning('Microsoft OAuth callback missing authorization code', [
                ...$this->oauthDiagnostics($request),
                'full_url' => $request->fullUrl(),
                'query_keys' => array_keys($request->query()),
            ]);

            return redirect()->route('login')
                ->with('error', $this->missingAuthorizationCodeMessage());
        }

        try {
            $microsoftUser = $this->azureDriver()->user();
        } catch (\Throwable $e) {
            $microsoftError = $this->microsoftOAuthErrorDetails($e);

            Log::error('Microsoft OAuth callback failed', [
                ...$this->oauthDiagnostics($request),
                'message' => $e->getMessage(),
                'class' => $e::class,
                'microsoft_error' => $microsoftError,
            ]);

            return redirect()->route('login')
                ->with('error', $this->publicOAuthErrorMessage($e, $microsoftError));
        }

        $email = strtolower($microsoftUser->getEmail() ?? '');
        $objectId = $microsoftUser->getId();

        Log::info('Microsoft OAuth callback succeeded', [
            'email' => $email,
            'object_id' => $objectId,
        ]);

        // Entra object id is stable across primary-email / domain changes. Prefer that row.
        $user = User::query()
            ->where('entra_object_id', $objectId)
            ->orderByDesc('is_active')
            ->orderByDesc('entra_synced_at')
            ->orderByDesc('id')
            ->first()
            ?? ($email !== ''
                ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
                : null);

        if (! $user) {
            Log::warning('Microsoft OAuth user not provisioned in portal', ['email' => $email]);

            return redirect()->route('login')
                ->with('error', 'Your account has not been set up. Please contact your administrator.');
        }

        if (! $user->is_active) {
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated. Please contact your administrator.');
        }

        if ($user->portal_login_enabled === false) {
            return redirect()->route('login')
                ->with('error', 'This account cannot sign in to the portal. Shared mailboxes are synced for support records only - please use your personal work account.');
        }

        $user->loadMissing('client');

        if ($user->client_id && $user->client && ! $user->client->is_active) {
            return redirect()->route('login')
                ->with('error', 'Your organisation is not active on the portal. Please contact your administrator.');
        }

        $loginAttributes = [
            'entra_object_id' => $objectId,
            'name' => $microsoftUser->getName() ?? $user->name,
            'last_login_at' => now(),
            'microsoft_tokens' => array_filter([
                'access_token' => $microsoftUser->token,
                'refresh_token' => $microsoftUser->refreshToken,
                'expires_in' => $microsoftUser->expiresIn,
            ]),
        ];

        // Keep portal email aligned with Microsoft primary when free (sync also renames by object id).
        if ($email !== '' && strtolower((string) $user->email) !== $email) {
            $emailTaken = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('id', '!=', $user->id)
                ->exists();
            if (! $emailTaken) {
                $loginAttributes['email'] = $email;
            }
        }

        $user->update($loginAttributes);

        $user = $user->fresh();
        $this->superOpsSync->syncUser($user);
        $this->superOpsSso->establishSsoSession($user);

        Auth::login($user, true);
        $request->session()->regenerate();

        $this->activityLog->log('user.login', $user);

        if (config('services.superops.auto_open_after_login') && $user->client_id) {
            return redirect()->intended(route('support.index'));
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function handleAdminConsentReturn(Request $request): RedirectResponse|View
    {
        Log::info('Microsoft admin consent completed', [
            ...$this->oauthDiagnostics($request),
            'tenant' => $request->query('tenant'),
            'state' => $request->query('state'),
        ]);

        $client = $this->clientFromAdminConsentState($request->query('state'));
        $tenantFromConsent = is_string($request->query('tenant'))
            ? strtolower(trim($request->query('tenant')))
            : null;

        // Microsoft always returns the customer tenant as a GUID. Anything else is not
        // a genuine consent return and must never reach bootstrap(), which persists it.
        if ($tenantFromConsent !== null && ! Str::isUuid($tenantFromConsent)) {
            $tenantFromConsent = null;
        }

        $bootstrapResult = null;

        // This route runs without a portal session, so it may link a tenant to a client
        // that has none yet, but never re-point an already-linked client elsewhere.
        // (adminConsentUrl() pins /{tenant}/adminconsent once known, so a genuine
        // re-consent always comes back with the same tenant.)
        if ($client && filled($client->entra_tenant_id) && $tenantFromConsent !== null
            && strtolower((string) $client->entra_tenant_id) !== $tenantFromConsent) {
            Log::warning('Admin consent tenant does not match client tenant - bootstrap skipped', [
                'client_id' => $client->id,
                'client_tenant' => $client->entra_tenant_id,
                'consent_tenant' => $tenantFromConsent,
            ]);

            return view('auth.admin-consent-complete', [
                'tenant' => $tenantFromConsent,
                'client' => null,
                'bootstrap' => [
                    'ok' => false,
                    'summary' => 'Consent tenant does not match this client.',
                    'details' => [],
                    'warnings' => [
                        'This Accept came back from a different Microsoft tenant than the one already linked to the client, so nothing was changed. '
                        .'Sign in to the portal and change the tenant on Edit client if the customer really moved tenants.',
                    ],
                ],
            ]);
        }

        if ($client) {
            // Link the tenant now (unlinked client + GUID from Microsoft only; the
            // guard above already refused re-pointing), then run the slow Graph
            // bootstrap on the queue. It used to run inline on this public URL
            // (~24s+ per request), a cheap worker-exhaustion lever.
            if ($tenantFromConsent !== null && blank($client->entra_tenant_id)) {
                $client->update(['entra_tenant_id' => $tenantFromConsent]);
            }

            if (filled($client->entra_tenant_id)) {
                BootstrapClientEntraJob::markQueued($client->id);
                BootstrapClientEntraJob::dispatch($client->id);

                $bootstrapResult = [
                    'ok' => false,
                    'summary' => 'Graph setup queued.',
                    'details' => [
                        'Graph setup (portal group + apps) is running in the background - usually under 2 minutes.',
                        'Sign in → Admin → Clients → Edit to see the result under Bootstrap Entra.',
                    ],
                    'warnings' => [],
                ];
            } else {
                $bootstrapResult = [
                    'ok' => false,
                    'summary' => 'No tenant ID from Accept or client record.',
                    'details' => [],
                    'warnings' => ['Run Connect Microsoft tenant first (Accept).'],
                ];
            }
        }

        return view('auth.admin-consent-complete', [
            'tenant' => $tenantFromConsent,
            'client' => $client,
            'bootstrap' => $bootstrapResult,
        ]);
    }

    private function clientFromAdminConsentState(mixed $state): ?Client
    {
        $clientId = \App\Support\AdminConsentState::decode(is_string($state) ? $state : null);

        return $clientId ? Client::find($clientId) : null;
    }

    private function azureDriver(): Provider
    {
        $driver = Socialite::driver('azure')
            ->redirectUrl(config('services.azure.redirect'))
            ->scopes(['openid', 'profile', 'email', 'User.Read']);

        if (config('services.azure.oauth_stateless')) {
            $driver->stateless();
        }

        return $driver;
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthDiagnostics(Request $request): array
    {
        return [
            'session_driver' => config('session.driver'),
            'session_id' => $request->session()->getId(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
            'session_cookie_name' => config('session.cookie'),
            'oauth_stateless' => (bool) config('services.azure.oauth_stateless'),
            'redirect_uri' => config('services.azure.redirect'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    private function publicOAuthErrorMessage(\Throwable $e, ?string $microsoftError = null): string
    {
        $microsoftError ??= $this->microsoftOAuthErrorDetails($e);
        $haystack = strtolower(($microsoftError ?? '').' '.$e->getMessage());

        if (config('app.debug')) {
            return 'Authentication failed: '.($microsoftError ?? $e->getMessage());
        }

        if ($e instanceof InvalidStateException) {
            return $this->sessionLostMessage();
        }

        if (str_contains($haystack, 'aadsts900144') || str_contains($haystack, "parameter: 'code'")) {
            return $this->missingAuthorizationCodeMessage();
        }

        if (str_contains($haystack, 'invalid_client') || str_contains($haystack, '7000215')) {
            return 'Microsoft client secret is invalid or expired. In Entra → OnIT Portal for Portals → Certificates & secrets, create a new secret, update MICROSOFT_CLIENT_SECRET in server .env, then php artisan config:clear.';
        }

        if (str_contains($haystack, 'redirect_uri') || str_contains($haystack, 'aadsts50011')) {
            return 'Redirect URI mismatch. Entra app → Authentication must include exactly: '.config('services.azure.redirect');
        }

        if (str_contains($haystack, 'invalid_grant') || str_contains($haystack, 'aadsts54005')) {
            return 'Microsoft sign-in expired or was already used. Close all login tabs, open a fresh window at https://app.onit.ltd/login, and try once (no VPN/private browsing if possible).';
        }

        if ($microsoftError) {
            return 'Microsoft rejected sign-in: '.Str::before($microsoftError, 'Trace ID');
        }

        if ($e instanceof RequestException || str_contains($haystack, 'curl error')) {
            return 'Server could not reach Microsoft to complete sign-in. Check outbound HTTPS from the server (see storage/logs/laravel.log).';
        }

        return 'Microsoft sign-in failed ('.class_basename($e).'). Run: tail -20 storage/logs/laravel.log on the server immediately after trying again.';
    }

    private function microsoftOAuthErrorDetails(\Throwable $e): ?string
    {
        if (! $e instanceof RequestException || $e->getResponse() === null) {
            return null;
        }

        $body = (string) $e->getResponse()->getBody();
        $json = json_decode($body, true);

        if (is_array($json)) {
            $error = $json['error'] ?? 'error';
            $description = $json['error_description'] ?? $json['error_uri'] ?? $body;

            return $error.': '.$description;
        }

        return $body !== '' ? $body : null;
    }

    private function missingAuthorizationCodeMessage(): string
    {
        return 'Microsoft did not return a sign-in code to the portal. '
            .'Open https://app.onit.ltd/login in a normal browser window (no private browsing, VPN off), click Sign in with Microsoft once, and wait - do not refresh, go back, or open multiple login tabs. '
            .'In Entra → OnIT Portal for Portals → Authentication, the redirect URI must be Web (not SPA): '.config('services.azure.redirect');
    }

    private function sessionLostMessage(): string
    {
        return 'Sign-in session was lost during the Microsoft redirect. '
            .'Use one browser window at https://app.onit.ltd/login - turn off VPN/private-browsing cookie blocking if possible, click Sign in with Microsoft once, and finish in the same tab. '
            .'Production should use MICROSOFT_OAUTH_STATELESS=true (now the default after git pull + config:clear).';
    }
}
