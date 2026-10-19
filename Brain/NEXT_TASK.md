# Next task

**Mail domains and DNS health** - the next big feature. Do this before Pax8 deeper.

Full programme: [MailDomainDns.md](MailDomainDns.md) (Phases A, B, C, and after).

## Build now: Phase A

1. Required **main domain** on Create Client (with company name).
2. `client_domains` + Edit panel (extra domains, custom DKIM selectors, Check DNS).
3. Public DNS checks: MX, SPF, DKIM (broad selectors), DMARC with green / yellow / red bands.
4. Checklist step + hand-off **warns** on red (does not block).
5. Staff only.

Stop after Phase A is verified, committed, and pushed. Do not start Phase B until asked.

## Then (same programme)

- **Phase B** - Entra verified-domain cross-check, nightly recheck, Integration Health notice.
- **Phase C** - Provider profiles, check history, optional CSV export.
- **After C** - Client Admin read-only view; optional Autodiscover / MTA-STS / BIMI.

## Waiting after this programme

- Pax8 billed catalogue (was previous NEXT_TASK).
- YorPower SuperOps duplicate email (human).
- usecure partner key in vault (human). Leave `USECURE_ENABLED=false`. Do not commit the local usecure skeleton.
- CoPilot Ready secret rotation if not done.
