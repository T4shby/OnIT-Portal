<?php

namespace Tests\Unit;

use App\Support\AdminConsentState;
use Tests\TestCase;

class AdminConsentStateTest extends TestCase
{
    public function test_encode_and_decode_round_trip(): void
    {
        $state = AdminConsentState::encode(42);

        $this->assertSame(42, AdminConsentState::decode($state));
    }

    public function test_decode_rejects_tampered_signature(): void
    {
        $state = AdminConsentState::encode(42);

        $this->assertNull(AdminConsentState::decode($state.'x'));
    }

    public function test_decode_rejects_unsigned_legacy_client_prefix_state(): void
    {
        // Unsigned states let anyone target any client id on the unauthenticated
        // consent callback - they must never decode.
        $this->assertNull(AdminConsentState::decode('client-7'));
    }

    public function test_decode_rejects_payload_that_is_not_a_canonical_integer(): void
    {
        $state = AdminConsentState::encode(42);
        [, $signature] = explode('.', $state, 2);

        $this->assertNull(AdminConsentState::decode('42abc.'.$signature));
        $this->assertNull(AdminConsentState::decode('.'.$signature));
    }
}
