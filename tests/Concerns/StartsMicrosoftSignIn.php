<?php

namespace Tests\Concerns;

use App\Support\MicrosoftOAuthState;

/**
 * Drives the real first half of Microsoft sign-in (GET /auth/microsoft with the real
 * Socialite driver), keeps the state cookie it sets for later requests in the test,
 * and returns the `state` Microsoft would echo back on the callback.
 *
 * Call this BEFORE mocking the Socialite facade for the callback's user() lookup.
 */
trait StartsMicrosoftSignIn
{
    protected function startMicrosoftSignIn(): string
    {
        config([
            'services.azure.client_id' => 'test-portal-client-id',
            'services.azure.client_secret' => 'test-portal-client-secret',
            'services.azure.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        $response = $this->get(route('auth.microsoft'));
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertIsString($query['state'] ?? null, 'The authorize URL must carry our state.');

        $name = MicrosoftOAuthState::nameFor(false);
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie, 'The redirect must set the state cookie.');

        // The test client has no cookie jar: carry the (decrypted) value forward; it is
        // re-encrypted for the next request exactly as a browser would send it back.
        $this->withCookie($name, (string) $cookie->getValue());

        return $query['state'];
    }
}
