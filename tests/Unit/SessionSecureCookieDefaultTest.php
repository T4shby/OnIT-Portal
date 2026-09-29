<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * config/session.php `secure` must default to true in production when
 * SESSION_SECURE_COOKIE is unset or blank (as a copied .env.example leaves it),
 * while still honouring an explicit value.
 */
class SessionSecureCookieDefaultTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string, bool}>
     */
    public static function cases(): array
    {
        return [
            'production, unset' => ['production', null, true],
            'production, blank' => ['production', '', true],
            'production, explicit false' => ['production', 'false', false],
            'production, explicit true' => ['production', 'true', true],
            'local, unset' => ['local', null, false],
            'local, explicit true' => ['local', 'true', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_secure_flag(string $appEnv, ?string $secureCookie, bool $expected): void
    {
        $saved = [];
        foreach (['APP_ENV' => $appEnv, 'SESSION_SECURE_COOKIE' => $secureCookie] as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        try {
            $config = require config_path('session.php');
            $this->assertSame($expected, $config['secure']);
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                $put === false ? putenv($key) : putenv($key.'='.$put);
            }
        }
    }
}
