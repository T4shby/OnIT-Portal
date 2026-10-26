# Next task

**Mail domains and DNS health** - the next big feature. Do this before Pax8 deeper.

Full programme: [MailDomainDns.md](MailDomainDns.md) (Phases A, B, C, and after).

## Build now: Phase A (code done)

Phase A is **live** (`f403fcb` / feature `34e3a88`, migrated). Code milestone is finished.

**Human follow-up:** every existing client still has an empty `primary_domain` (checked 2026-10-26: all 9 active clients). On Edit Client, set **Main domain** and run **Check DNS** for the customers being onboarded. New creates already require a domain.

Do not start Phase B until asked.

## Then (same programme)

- **Phase B** - Entra verified-domain cross-check, nightly recheck, Integration Health notice.
- **Phase C** - Provider profiles, check history, optional CSV export.
- **After C** - Client Admin read-only view; optional Autodiscover / MTA-STS / BIMI.

## Waiting after this programme

- Pax8 billed catalogue (was previous NEXT_TASK).
- YorPower SuperOps duplicate email (human).
- usecure partner key in vault (human). Leave `USECURE_ENABLED=false`. Do not commit the local usecure skeleton.
- CoPilot Ready secret rotation if not done.
