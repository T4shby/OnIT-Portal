# Next task

**Passkey / SMS-voice MFA Phase 2** when Tom asks - stored findings / campaigns. Phase 1 + Client Admin portal page are on `main` (deploy 3 Nov 2026).

Full programme: [PasskeyMfaMigration.md](PasskeyMfaMigration.md).

## Live now: Phase 1 + Client Admin

- Staff: Admin → Clients → Edit → **MFA methods / passkey readiness**
- Client Admin: Services → **MFA methods** (`/microsoft-365/mfa-methods`) → Refresh → CSV

Do not start Phase 2 until asked.

## Waiting

- Deploy Mail Phase C + IH alerts when Tom asks (migration `client_domain_dns_checks`; set Teams webhook / alert email then enable).
- Pax8 billed catalogue.
- YorPower SuperOps duplicate email (human).
- usecure partner key in vault (human). Leave `USECURE_ENABLED=false`. Do not commit the local usecure skeleton.
