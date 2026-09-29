<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\MicrosoftOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\Concerns\StartsMicrosoftSignIn;
use Tests\TestCase;

/**
 * Pass 8 (L11): the Microsoft sign-in callback must only complete a sign-in that THIS
 * browser started in the last few minutes. The state nonce lives in a dedicated
 * encrypted cookie (MicrosoftOAuthState), not the session, and Socialite runs
 * stateless. Without the check an attacker can log a victim into the attacker's
 * account by sending them a callback URL carrying the attacker's own code (login CSRF).
 */
class MicrosoftOAuthStateTest extends TestCase
{
    use RefreshDatabase;
    use StartsMicrosoftSignIn;

    private const OBJECT_ID = 'aaaaaaaa-1111-2222-3333-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function staffUser(): User
    {
        return User::factory()->create([
            'email' => 'tech@onit.example',
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
            'is_active' => true,
            'portal_login_enabled' => true,
            'entra_object_id' => self::OBJECT_ID,
        ]);
    }

    /** Callback's Microsoft user lookup succeeds (the code exchange is mocked). */
    private function microsoftReturnsUser(): void
    {
        $socialiteUser = new class
        {
            public string $token = 'access-token';

            public ?string $refreshToken = null;

            public ?int $expiresIn = 3600;

            public function getEmail(): string
            {
                return 'tech@onit.example';
            }

            public function getId(): string
            {
                return 'aaaaaaaa-1111-2222-3333-444444444444';
            }

            public function getName(): string
            {
                return 'Tech';
            }
        };

        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('stateless')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('azure')->andReturn($driver);
    }

    /** The callback must be refused before any code exchange with Microsoft. */
    private function microsoftMustNotBeAsked(): void
    {
        Socialite::shouldReceive('driver')->never();
    }

    private function hitCallback(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('auth.microsoft.callback', $query));
    }

    private function assertRefusedWith(\Illuminate\Testing\TestResponse $response, string $needle): void
    {
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', fn (string $m) => str_contains($m, $needle));
        $this->assertGuest();
    }

    public function test_redirect_sets_a_short_lived_http_only_lax_state_cookie_and_sends_the_same_state(): void
    {
        $state = $this->startMicrosoftSignIn();

        $cookie = $this->get(route('auth.microsoft'))->getCookie(MicrosoftOAuthState::nameFor(false));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain(), 'host-only cookie');
        $this->assertEqualsWithDelta(now()->addMinutes(15)->getTimestamp(), $cookie->getExpiresTime(), 5);

        $entries = json_decode($cookie->getValue(), true);
        $this->assertContains($state, array_column($entries, 's'));
        $this->assertGreaterThanOrEqual(40, strlen($state));

        // Nothing about the state is kept in the session any more.
        $this->assertFalse(session()->has('state'));
    }

    public function test_valid_state_cookie_and_matching_state_signs_the_user_in(): void
    {
        $user = $this->staffUser();
        $state = $this->startMicrosoftSignIn();
        $this->microsoftReturnsUser();

        $response = $this->hitCallback(['code' => 'auth-code', 'state' => $state]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // One use: the matched state is removed (the cookie is deleted when empty).
        $cleared = $response->getCookie(MicrosoftOAuthState::nameFor(false));
        $this->assertNotNull($cleared);
        $this->assertLessThan(now()->getTimestamp(), $cleared->getExpiresTime());
    }

    public function test_callback_without_the_state_cookie_is_refused(): void
    {
        $this->staffUser();
        $this->microsoftMustNotBeAsked();

        $response = $this->hitCallback(['code' => 'auth-code', 'state' => 'anything']);

        $this->assertRefusedWith($response, 'did not send back');
    }

    public function test_login_csrf_attackers_code_and_state_are_refused_in_the_victims_browser(): void
    {
        $this->staffUser();
        // The victim's browser started its own sign-in, so it has a state cookie...
        $this->startMicrosoftSignIn();
        $this->microsoftMustNotBeAsked();

        // ...but the link the attacker sent carries the attacker's code and state.
        $response = $this->hitCallback(['code' => 'ATTACKER-code', 'state' => 'attacker-state-from-their-own-browser']);

        $this->assertRefusedWith($response, 'did not match');
    }

    public function test_callback_with_no_state_parameter_is_refused(): void
    {
        $this->staffUser();
        $this->startMicrosoftSignIn();
        $this->microsoftMustNotBeAsked();

        $this->assertRefusedWith($this->hitCallback(['code' => 'auth-code']), 'did not match');
    }

    public function test_expired_state_is_refused(): void
    {
        $this->staffUser();
        $state = $this->startMicrosoftSignIn();
        $this->microsoftMustNotBeAsked();

        $this->travel(MicrosoftOAuthState::TTL_MINUTES)->minutes();
        $this->travel(5)->seconds();

        $this->assertRefusedWith($this->hitCallback(['code' => 'auth-code', 'state' => $state]), 'took longer than 15 minutes');
    }

    public function test_state_just_inside_the_window_still_signs_in(): void
    {
        $user = $this->staffUser();
        $state = $this->startMicrosoftSignIn();
        $this->microsoftReturnsUser();

        // A slow MFA prompt / password change during sign-in.
        $this->travel(MicrosoftOAuthState::TTL_MINUTES - 1)->minutes();

        $this->hitCallback(['code' => 'auth-code', 'state' => $state])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_forged_unencrypted_state_cookie_is_ignored(): void
    {
        $this->staffUser();
        $this->microsoftMustNotBeAsked();

        // Without APP_KEY an attacker cannot mint a cookie EncryptCookies will accept.
        $this->withUnencryptedCookie(
            MicrosoftOAuthState::nameFor(false),
            json_encode([['s' => 'forged-state', 't' => now()->getTimestamp()]]),
        );

        $this->assertRefusedWith($this->hitCallback(['code' => 'auth-code', 'state' => 'forged-state']), 'did not send back');
    }

    public function test_crafted_error_text_is_not_echoed_without_a_valid_state(): void
    {
        $this->microsoftMustNotBeAsked();

        $response = $this->hitCallback([
            'error' => 'access_denied',
            'error_description' => 'Your account is locked. Call 0800 000 000 to unlock it.',
        ]);

        $response->assertSessionHas('error', fn (string $m) => ! str_contains($m, '0800'));
        $this->assertGuest();
    }

    public function test_sign_ins_started_in_two_tabs_can_both_complete(): void
    {
        $user = $this->staffUser();
        $first = $this->startMicrosoftSignIn();
        $second = $this->startMicrosoftSignIn();
        $this->assertNotSame($first, $second);
        $this->microsoftReturnsUser();

        // The older tab finishes first; its state is still in the cookie.
        $this->hitCallback(['code' => 'auth-code', 'state' => $first])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_over_https_the_cookie_is_secure_and_host_prefixed(): void
    {
        // Even with a SESSION_DOMAIN set: a Domain attribute would make browsers
        // reject a `__Host-` cookie outright.
        config(['session.secure' => true, 'session.domain' => '.onit.example']);
        $user = $this->staffUser();
        $state = $this->startMicrosoftSignInOverHttps();
        $this->microsoftReturnsUser();

        $this->hitCallback(['code' => 'auth-code', 'state' => $state])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    private function startMicrosoftSignInOverHttps(): string
    {
        config([
            'services.azure.client_id' => 'test-portal-client-id',
            'services.azure.client_secret' => 'test-portal-client-secret',
            'services.azure.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        $response = $this->get(route('auth.microsoft'));
        $this->assertNull($response->getCookie(MicrosoftOAuthState::nameFor(false), false));

        $cookie = $response->getCookie('__Host-onit_oauth_state');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        $header = collect($response->headers->all('set-cookie'))
            ->first(fn (string $h) => str_starts_with($h, '__Host-onit_oauth_state='));
        $this->assertNotNull($header);
        $this->assertStringContainsStringIgnoringCase('; secure', $header);
        $this->assertStringContainsStringIgnoringCase('; httponly', $header);
        $this->assertStringNotContainsStringIgnoringCase('domain=', $header);

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->withCookie('__Host-onit_oauth_state', (string) $cookie->getValue());

        return $query['state'];
    }

    public function test_redirect_moves_to_the_callback_host_once_so_the_cookie_lands_where_microsoft_returns(): void
    {
        config([
            'services.azure.client_id' => 'test-portal-client-id',
            'services.azure.client_secret' => 'test-portal-client-secret',
            'services.azure.redirect' => 'http://127.0.0.1:8000/auth/microsoft/callback',
        ]);

        $bounce = $this->get('http://localhost/auth/microsoft');
        $bounce->assertRedirect('http://127.0.0.1:8000/auth/microsoft?host_redirected=1');
        $this->assertNull($bounce->getCookie(MicrosoftOAuthState::nameFor(false), false));

        // If a proxy still reports another host, do not loop: carry on to Microsoft.
        $this->get('http://localhost/auth/microsoft?host_redirected=1')
            ->assertRedirectContains('https://login.microsoftonline.com/');
    }

    public function test_the_stateless_config_switch_no_longer_exists(): void
    {
        $this->assertArrayNotHasKey('oauth_stateless', config('services.azure'));
    }
}
