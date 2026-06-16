# On IT Portal — Brain (Source of Truth)

Read these documents before changing application code. **Update Brain in the same change** — this folder is the handover doc for junior engineers and future maintainers.

## For Junior Engineers

1. Start here, then read [LocalDevelopment.md](LocalDevelopment.md) to run the app on Windows.
2. Read the doc for the area you are changing (auth, SuperOps, portal links, etc.).
3. After every code or config change, update the relevant Brain file in the same PR/commit.
4. If you hit an error locally, check [LocalDevelopment.md — Common Local Errors](LocalDevelopment.md#common-local-errors) first.

## Document Index

| Document | Purpose |
|---|---|
| [ProjectVision.md](ProjectVision.md) | Strategic goals and product positioning |
| [ProductRequirements.md](ProductRequirements.md) | MVP scope, user stories, functional requirements |
| [Architecture.md](Architecture.md) | Layers, flows, directory structure |
| [LocalDevelopment.md](LocalDevelopment.md) | **Windows dev setup, SSL fix, troubleshooting** |
| [Authentication.md](Authentication.md) | Microsoft Entra ID login and sessions |
| [SuperOpsIntegration.md](SuperOpsIntegration.md) | Embedded support + SSO launch |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | **Entra SAML setup for SuperOps requesters (no technician SSO)** |
| [OperatorRunbook.md](OperatorRunbook.md) | **Step-by-step checklist — finish SSO, onboard users, production** |
| [ClientOnboarding.md](ClientOnboarding.md) | **Master checklist — new client, new user, Path A vs B, all systems** |
| [NewClientSetupGuide.md](NewClientSetupGuide.md) | **Start-to-finish guide for colleagues onboarding a real customer** |
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | **Simple guide for non-technical staff (60-client scale)** |
| [AccessAndSync.md](AccessAndSync.md) | **M365 sync, offboarding, what is automatic today vs planned** |
| [EntraGroupSync.md](EntraGroupSync.md) | **Phase 2: configure and run Entra group sync** |
| [PortalLinks.md](PortalLinks.md) | Link types and resolution |
| [DatabaseSchema.md](DatabaseSchema.md) | Tables, columns, tenant rules |
| [CustomerJourney.md](CustomerJourney.md) | End-to-end user flows |
| [UIUX.md](UIUX.md) | Layout, components, wireframes |
| [Deployment.md](Deployment.md) | Plesk production, `.env`, Entra + SuperOps setup |
| [Decisions.md](Decisions.md) | Architectural decision records |
| [Roadmap.md](Roadmap.md) | Phased future work |

## Implementation Map

| Brain topic | Code |
|---|---|
| Entra login | `app/Http/Controllers/Auth/MicrosoftAuthController.php` |
| SuperOps API | `app/Services/SuperOps/SuperOpsApiClient.php` |
| Embedded support | `app/Http/Controllers/SupportController.php` |
| SSO launch | `app/Http/Controllers/Integrations/SuperOpsLaunchController.php` |
| Portal links | `app/Models/PortalLink.php`, `ExternalServicesService.php` |
| Config | `config/services.php` |

## Change Protocol

1. Read relevant Brain doc(s)
2. Implement code to match
3. **Update Brain** — same session/PR as the code change; do not defer documentation
4. Add or update ADRs in [Decisions.md](Decisions.md) for non-obvious choices (paths, integrations, security)

## Last Updated

| Date | Change |
|---|---|
| 2026-06-16 | Removed Acme/Globex/Initech demo seed defaults; added `portal:purge-demo-data` cleanup command |
| 2026-06-16 | Added production security hardening: HTTPS forced in app + default security headers middleware |
| 2026-06-15 | Production Laravel URL set to `app.onit.ltd` (`portal.onit.ltd` = SuperOps only) |
| 2026-06-15 | SuperOps launch: direct redirect to `/#/requester/login` (removed interim instructions page) |
| 2026-06-15 | Documented IDP Login URL source (Entra Section 4), URL placement table, Step 2 Save requirement |
| 2026-06-15 | Fix SuperOps launch: do not redirect to Entra SAML URL directly (`AADSTS750054`) |
| 2026-06-12 | On IT Technology Partners client + `portal.test@onit.ltd` seeder; M365 sync deferred to Phase 2 (ADR-014) |
| 2026-06-12 | Added [ClientOnboarding.md](ClientOnboarding.md) — master new client/user checklist, On IT SAML values |
| 2026-06-12 | Added [OperatorRunbook.md](OperatorRunbook.md) — phased operator checklist |
| 2026-06-12 | SuperOps guide: provisioning at scale, groups vs manual, multi-tenant Client SSO note |
| 2026-06-12 | SuperOps Requester SSO guide updated with On IT Consumer Service URL (`portal.onit.ltd`) |
| 2026-06-12 | Added [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) — requester-only Entra SAML guide |
| 2026-06-12 | Documented Entra app split: portal (OAuth) vs SuperOps requester/technician (SAML) |
| 2026-06-12 | SuperOps launch uses requester portal URL (`{subdomain}.superops.ai`); removed broken `/login/sso` fallback |
| 2026-06-12 | Dashboard simplified to SuperOps + Pax8 only; portal link seeder syncs and dedupes |
| 2026-06-12 | Added `remember_token` to users table — fixes login 500 after Microsoft OAuth callback |
| 2026-06-12 | Added [LocalDevelopment.md](LocalDevelopment.md) — Windows path, SQLite, PHP SSL/cURL error 60 fix |

## Session Handoff (paste into a new chat)

```
You are the senior engineer on the On IT Portal project.

**Canonical project path:** C:\Dev\OnIT-Portal
(Mirror copy synced to OneDrive workspace — develop from C:\Dev only.)

**Source of truth:** Read Brain/README.md first, then the relevant Brain/*.md before any code change.
**Protocol:** Update Brain in the same change as code. Document for junior engineers.

**Stack:** Laravel 11, Blade, Tailwind, Alpine.js, SQLite (local), MySQL (production Plesk).
**Auth:** Microsoft Entra ID multi-tenant (organizations) via Socialite.
**Integrations:** SuperOps embedded support + SSO launch (see Brain/SuperOpsIntegration.md). Requester SSO setup: Brain/SuperOpsRequesterSsoSetup.md. Requester SSO setup: Brain/SuperOpsRequesterSsoSetup.md.

**Local dev:** php artisan serve --host=localhost --port=8000 → http://localhost:8000/login
**Local setup:** Brain/LocalDevelopment.md (includes Windows PHP SSL/cacert.pem fix).

**Key routes:** /login, /auth/microsoft/callback, /dashboard, /support, /integrations/superops/launch, /admin/*
**Key code:** app/Http/Controllers/Auth/MicrosoftAuthController.php, app/Services/SuperOps/*

**Current state:** MVP built. Microsoft OAuth login works. SuperOps requester SSO is live in production at **`https://app.onit.ltd`**. `portal.onit.ltd` remains the SuperOps requester portal.

**SuperOps launch:** `SuperOpsSsoService` — client users only, `login_hint`, path `/#/requester/login`. Tests: `tests/Unit/SuperOpsSsoServiceTest.php`.

Do not commit .env. Do not develop from OneDrive-synced folders.
```
