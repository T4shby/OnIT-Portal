<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SuperOps\SuperOpsSsoService;
use App\Services\SuperOps\SuperOpsUserSyncService;
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

        // Persist session before leaving for Microsoft — avoids InvalidStateException when
        // the callback returns before the session cookie is written (common behind Plesk/nginx).
        $request->session()->save();

        return $this->azureDriver()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $description = $request->string('error_description')->before('Trace ID')->trim();

            Log::warning('Microsoft OAuth error redirect', [
                'error' => $request->input('error'),
                'description' => $description,
            ]);

            return redirect()->route('login')
                ->with('error', $this->authErrorMessage(
                    'Microsoft sign-in was rejected: '.$description
                ));
        }

        try {
            $microsoftUser = $this->azureDriver()->user();
        } catch (InvalidStateException $e) {
            Log::error('Microsoft OAuth callback failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
                'session_id' => $request->session()->getId(),
                'has_session_cookie' => $request->hasCookie(config('session.cookie')),
            ]);

            return redirect()->route('login')
                ->with('error', $this->sessionLostMessage());
        } catch (\Exception $e) {
            Log::error('Microsoft OAuth callback failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return redirect()->route('login')
                ->with('error', $this->authErrorMessage(
                    'Authentication failed: '.$e->getMessage()
                ));
        }

        $email = strtolower($microsoftUser->getEmail() ?? '');

        $user = User::where('entra_object_id', $microsoftUser->getId())->first()
            ?? User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
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

    private function sessionLostMessage(): string
    {
        return 'Sign-in session was lost during Microsoft redirect. '
            .'Use https://app.onit.ltd/login in one browser window (allow cookies), click Sign in with Microsoft once, and complete login without switching tabs. '
            .'If this keeps happening, ask your administrator to set MICROSOFT_OAUTH_STATELESS=true in production .env and run php artisan config:clear.';
    }

    private function authErrorMessage(string $detailed): string
    {
        if (config('app.debug')) {
            return $detailed;
        }

        return 'Authentication failed. Please try again.';
    }
}
