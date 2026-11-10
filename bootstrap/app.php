<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            // SecurityHeaders stays outermost so the deactivation redirect gets headers too.
            SecurityHeaders::class,
            EnsureAccountIsActive::class,
        ]);

        // TRUSTED_PROXIES=* (default) for single-box Plesk. Prefer comma-separated
        // proxy CIDRs/IPs in production when the upstream address is known.
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        if (is_string($trustedProxies) && $trustedProxies !== '' && $trustedProxies !== '*') {
            $trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
        } else {
            $trustedProxies = '*';
        }

        $middleware->trustProxies(
            at: $trustedProxies === [] ? '*' : $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
