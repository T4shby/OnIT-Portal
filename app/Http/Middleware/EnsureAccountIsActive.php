<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-applies the account checks MicrosoftAuthController::callback() makes at sign-in
 * on every authenticated request.
 *
 * Sign-in issues a remember-me cookie (SessionGuard default: 400 days), so without
 * this a user deactivated by an admin or by Entra sync (left the customer / account
 * disabled in M365), or whose whole organisation was deactivated, kept full portal
 * access until that cookie expired.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $reason = match (true) {
            ! $user->is_active => 'Your account has been deactivated. Please contact your administrator.',
            $user->portal_login_enabled === false => 'This account cannot sign in to the portal. Shared mailboxes are synced for support records only - please use your personal work account.',
            $user->client_id && $user->client && ! $user->client->is_active => 'Your organisation is not active on the portal. Please contact your administrator.',
            default => null,
        };

        if ($reason === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->with('error', $reason);
    }
}
