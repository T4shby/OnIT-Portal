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

    public function test_decode_supports_legacy_client_prefix_state(): void
    {
        $this->assertSame(7, AdminConsentState::decode('client-7'));
    }
}
