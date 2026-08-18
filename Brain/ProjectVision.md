# On IT Portal - Project Vision

## Purpose

Unified branded front door for On IT clients across Microsoft 365, SuperOps support, licensing, and resources.

## Core Flow

```
Customer → Entra ID → On IT Portal
                        ├── Embedded SuperOps support
                        ├── SuperOps SSO (full portal)
                        └── External launches (Pax8, M365, …)
```

SuperOps remains source of truth for tickets. The portal embeds and SSO-launches - it does not replace SuperOps.

## Success Metrics (MVP)

- One Microsoft login; embedded support without second credential
- Zero cross-tenant data exposure
- Admins manage content without developer help
- Stable on Plesk at 50 concurrent users

## Long-Term

Evolve into customer success platform - see [Roadmap.md](Roadmap.md).
