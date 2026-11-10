<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // Alpine + Vite-built assets need inline script/style; tighten later with nonces if needed.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; "
            ."img-src 'self' data: https:; font-src 'self' data:; "
            ."style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
            ."connect-src 'self'; object-src 'none'"
        );

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
