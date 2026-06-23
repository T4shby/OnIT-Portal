<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SuperOps\SuperOpsSsoService;
use App\Services\SuperOps\SuperOpsUserSyncService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $description = $request->string('error_description')->before('Trace ID')->trim();

            Log::warning('Microsoft OAuth error redirect', [
                ...$this->oauthDiagnostics($request),
                'microsoft_error' => $request->input('error'),
                'description' => $description,
            ]);

            return redirect()->route('login')
                ->with('error', $this->publicOAuthErrorMessage(
                    new \RuntimeException('Microsoft sign-in was rejected: '.$description)
                ));
        }

        try {
            $microsoftUser = $this->azureDriver()->user();
        } catch (\Throwable $e) {
            Log::error('Microsoft OAuth callback failed', [
                ...$this->oauthDiagnostics($request),
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return redirect()->route('login')
                ->with('error', $this->publicOAuthErrorMessage($e));
        }

        $email = strtolower($microsoftUser->getEmail() ?? '');

        Log::info('Microsoft OAuth callback succeeded', [
            'email' => $email,
            'object_id' => $microsoftUser->getId(),
        ]);

        $user = User::where('entra_object_id', $microsoftUser->getId())->first()
            ?? User::whereRaw('LOWER(email) = ?', [$email])->first();

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
                ->with('error', 'This account cannot sign in to the portal. Shared mailboxes are synced for support records only — please use your personal work account.');
        }

        $user->update([
            'entra_object_id' => $microsoftUser->getId(),
            'name' => $microsoftUser->getName() ?? $user->name,
            'last_login_at' => now(),
            'microsoft_tokens' => array_filter([
                'access_token' => $microsoftUser->token,
                'refresh_token' => $microsoftUser->refreshToken,
                'expires_in' => $microsoftUser->expiresIn,
            ]),
        ]);

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

    private function publicOAuthErrorMessage(\Throwable $e): string
    {
        if (config('app.debug')) {
            return 'Authentication failed: '.$e->getMessage();
        }

        if ($e instanceof InvalidStateException) {
            return $this->sessionLostMessage();
        }

        $message = $e->getMessage();

        if (str_contains($message, 'invalid_client') || str_contains($message, '7000215')) {
            return 'Microsoft client secret is invalid or expired. Update MICROSOFT_CLIENT_SECRET on the server and run php artisan config:clear.';
        }

        if (str_contains(strtolower($message), 'redirect_uri') || str_contains($message, 'AADSTS50011')) {
            return 'Redirect URI mismatch. Entra app registration must include exactly: '.config('services.azure.redirect');
        }

        if ($e instanceof RequestException || str_contains($message, 'cURL error')) {
            return 'Server could not reach Microsoft to complete sign-in. Check outbound HTTPS from the server (see storage/logs/laravel.log).';
        }

        return 'Microsoft sign-in failed ('.class_basename($e).'). Run: tail -20 storage/logs/laravel.log on the server immediately after trying again.';
    }

    private function sessionLostMessage(): string
    {
        return 'Sign-in session was lost during the Microsoft redirect. '
            .'Use one browser window at https://app.onit.ltd/login — turn off VPN/private-browsing cookie blocking if possible, click Sign in with Microsoft once, and finish in the same tab. '
            .'Production should use MICROSOFT_OAUTH_STATELESS=true (now the default after git pull + config:clear).';
    }
}
