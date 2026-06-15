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
use Laravel\Socialite\Facades\Socialite;

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

    public function redirect(): RedirectResponse
    {
        if (! config('services.azure.client_id') || ! config('services.azure.client_secret')) {
            return redirect()->route('login')
                ->with('error', 'Microsoft sign-in is not configured. Add MICROSOFT_CLIENT_ID and MICROSOFT_CLIENT_SECRET to your .env file.');
        }

        return Socialite::driver('azure')
            ->redirectUrl(config('services.azure.redirect'))
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->redirect();
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
            $microsoftUser = Socialite::driver('azure')
                ->redirectUrl(config('services.azure.redirect'))
                ->user();
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

    private function authErrorMessage(string $detailed): string
    {
        if (config('app.debug')) {
            return $detailed;
        }

        return 'Authentication failed. Please try again.';
    }
}
