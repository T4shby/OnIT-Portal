# Next task

**Mail domains and DNS health** - Phases A+B done in code. Pax8 deeper still waits.

Full programme: [MailDomainDns.md](MailDomainDns.md).

## Just finished: Phase B

Entra **Import from Microsoft** (verified domains, or mailbox email suffixes without Domain.Read.All), seeds empty primary after Connect, cross-check on Edit, nightly DNS recheck, Integration Health notices.

**Live** as of 2026-10-27 (`2d34082`). To fill empty primaries:

```bash
php artisan portal:sync-entra-mail-domains --empty-primary-only
```

(or Edit Client → **Import from Microsoft**). Optionally add Graph **Domain.Read.All** + Grant + customer re-consent for the full verified-domain list.

Do not start Phase C until asked.

## Then (same programme)

- **Phase C** - Provider profiles, check history, optional CSV export.
- **After C** - Client Admin read-only view; optional Autodiscover / MTA-STS / BIMI.

## Waiting after this programme

- Pax8 billed catalogue (was previous NEXT_TASK).
- YorPower SuperOps duplicate email (human).
- usecure partner key in vault (human). Leave `USECURE_ENABLED=false`. Do not commit the local usecure skeleton.
- CoPilot Ready secret rotation if not done.
