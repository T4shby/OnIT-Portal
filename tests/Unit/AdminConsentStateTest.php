<?php

namespace Tests\Unit;

use App\Support\AdminConsentState;
use Tests\TestCase;

class AdminConsentStateTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_encode_and_decode_round_trip(): void
    {
        $state = AdminConsentState::encode(42);

        $this->assertSame(42, AdminConsentState::decode($state));
        $this->assertSame(
            ['status' => AdminConsentState::STATUS_VALID, 'client_id' => 42],
            AdminConsentState::inspect($state),
        );
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
        [, $issuedAt, $signature] = explode('.', $state, 3);

        $this->assertNull(AdminConsentState::decode('42abc.'.$issuedAt.'.'.$signature));
        $this->assertNull(AdminConsentState::decode('.'.$issuedAt.'.'.$signature));
        $this->assertNull(AdminConsentState::decode('42.'.$issuedAt.'x.'.$signature));
    }

    public function test_state_is_valid_throughout_the_ttl_window(): void
    {
        config(['services.entra_sync.admin_consent_link_ttl_hours' => 24]);
        $state = AdminConsentState::encode(42);

        // A technician who copied the link and only got GDAP access later that day.
        $this->travel(23)->hours();
        $this->assertSame(42, AdminConsentState::decode($state));

        $this->travel(59)->minutes();
        $this->assertSame(42, AdminConsentState::decode($state));
    }

    public function test_state_older_than_the_ttl_is_rejected_as_expired(): void
    {
        config(['services.entra_sync.admin_consent_link_ttl_hours' => 24]);
        $state = AdminConsentState::encode(42);

        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        $this->assertNull(AdminConsentState::decode($state));
        $this->assertSame(
            ['status' => AdminConsentState::STATUS_EXPIRED, 'client_id' => null],
            AdminConsentState::inspect($state),
        );
    }

    public function test_issued_at_is_covered_by_the_signature_so_an_old_link_cannot_be_refreshed(): void
    {
        $old = AdminConsentState::encode(42, now()->subDays(30)->getTimestamp());
        [$clientId, , $signature] = explode('.', $old, 3);

        // Attacker keeps the leaked signature and swaps in a fresh timestamp.
        $forged = $clientId.'.'.now()->getTimestamp().'.'.$signature;

        $this->assertSame(AdminConsentState::STATUS_EXPIRED, AdminConsentState::inspect($old)['status']);
        $this->assertSame(AdminConsentState::STATUS_INVALID, AdminConsentState::inspect($forged)['status']);
        $this->assertNull(AdminConsentState::decode($forged));
    }

    public function test_pre_expiry_two_part_states_are_no_longer_accepted(): void
    {
        // The old format `{id}.{hmac(id)}` carried no timestamp, so it never expired.
        $legacy = '42.'.hash_hmac('sha256', '42', (string) config('app.key'));

        $this->assertNull(AdminConsentState::decode($legacy));
    }

    public function test_issued_at_far_in_the_future_is_rejected(): void
    {
        $future = AdminConsentState::encode(42, now()->addDay()->getTimestamp());

        $this->assertNull(AdminConsentState::decode($future));
    }

    public function test_ttl_is_clamped(): void
    {
        config(['services.entra_sync.admin_consent_link_ttl_hours' => 0]);
        $this->assertSame(1, AdminConsentState::ttlHours());

        config(['services.entra_sync.admin_consent_link_ttl_hours' => 100000]);
        $this->assertSame(336, AdminConsentState::ttlHours());
    }
}
