# Next task

**Mail domains and DNS health** - the next big feature. Do this before Pax8 deeper.

Full programme: [MailDomainDns.md](MailDomainDns.md) (Phases A, B, C, and after).

## Build now: Phase A

Phase A is **complete on GitHub `main`** (required main domain, DNS checks, checklist step 00, create intro, hand-off red warning, SPF/DMARC unit tests). **Deploy when Tom asks** (`migrate` required). Then onboard the six customers.

Do not start Phase B until asked. Do not deploy without Tom asking.

## Then (same programme)

- **Phase B** - Entra verified-domain cross-check, nightly recheck, Integration Health notice.
- **Phase C** - Provider profiles, check history, optional CSV export.
- **After C** - Client Admin read-only view; optional Autodiscover / MTA-STS / BIMI.

## Waiting after this programme

- Pax8 billed catalogue (was previous NEXT_TASK).
- YorPower SuperOps duplicate email (human).
- usecure partner key in vault (human). Leave `USECURE_ENABLED=false`. Do not commit the local usecure skeleton.
- CoPilot Ready secret rotation if not done.
