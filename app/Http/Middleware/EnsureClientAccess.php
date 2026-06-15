<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $clientId = $request->route('client')?->id ?? $request->input('client_id') ?? $request->route('client_id');

        if ($clientId && ! $user->canAccessClient((int) $clientId)) {
            abort(403, 'You do not have access to this client.');
        }

        return $next($request);
    }
}
