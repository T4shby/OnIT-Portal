# On IT Portal — Brain (Source of Truth)

Read these documents before changing application code. **Update Brain for every meaningful change in the same turn** (code, checklist, Entra/SSO, deploy, env) — not only “docs tickets”. This folder is the handover doc for technicians and maintainers.

## For Junior Engineers

1. Start here, then read [LocalDevelopment.md](LocalDevelopment.md) to run the app on Windows.
2. Read the doc for the area you are changing (auth, SuperOps, portal links, etc.).
3. **Every** code or config change that affects how the app or ops works → update the relevant Brain file **in the same change** (same PR/commit). No exceptions for “I’ll document later”.
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
| [Pax8Integration.md](Pax8Integration.md) | **Pax8 SSO launch (dashboard tile → partner or company view)** |
| [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md) | **Pax8 Enterprise SSO for technicians (Primary Partner Admin, DNS, Finalize)** |
| [CustomerPortalSso.md](CustomerPortalSso.md) | **Customer SSO — portal + SuperOps requester + Pax8 access** |
| [Pax8CustomerAccess.md](Pax8CustomerAccess.md) | **Pax8 company view for clients (no customer IdP SSO)** |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | **Entra SAML setup for SuperOps requesters** |
| [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) | **Entra SAML setup for SuperOps technicians (On IT staff)** |
| [OperatorRunbook.md](OperatorRunbook.md) | **Step-by-step checklist — finish SSO, onboard users, production** |
| [ClientOnboarding.md](ClientOnboarding.md) | Master reference — platform values, verification, anti-patterns |
| [NewClientSetupGuide.md](NewClientSetupGuide.md) | **SUPERSEDED** — redirects to TechnicianTenantOnboarding |
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | Simple guide for non-technical staff |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | **Only technician entry point** — mirrors live 12-step checklist |
| [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) | Deep narrative reference (not day-to-day start) |
| [AccessAndSync.md](AccessAndSync.md) | **Two-sync model: M365 → SuperOps SCIM + M365 → Portal** |
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | **Sync 1: SuperOps SCIM** — Bearer auth, Entra ID Free, requester names `(User Mailbox)` / `(Shared Mailbox)` |
| [EntraGroupSync.md](EntraGroupSync.md) | **Sync 2: Portal Entra group sync** |
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | **Complete re-do guide — permissions, group, SCIM, SAML, deploy, troubleshooting** |
| [PortalLinks.md](PortalLinks.md) | Link types and resolution |
| [DatabaseSchema.md](DatabaseSchema.md) | Tables, columns, tenant rules |
| [CustomerJourney.md](CustomerJourney.md) | End-to-end user flows |
| [UIUX.md](UIUX.md) | **Portal design system** (maps to marketing skill) |
| [design/README.md](design/README.md) | Canonical copy of onit.ltd SKILL, design-system, components |
| [Deployment.md](Deployment.md) | Plesk production, `.env`, Entra + SuperOps setup |
| [Decisions.md](Decisions.md) | Architectural decision records |
| [Roadmap.md](Roadmap.md) | Phased future work |
| [ClientAdminDashboard.md](ClientAdminDashboard.md) | **Client Admin roles, SuperOps/Huntress metrics, async M365 directory and insights** |

## Implementation Map

| Brain topic | Code |
|---|---|
| Entra login | `app/Http/Controllers/Auth/MicrosoftAuthController.php` |
| Entra group sync | `app/Services/EntraSync/EntraGroupSyncService.php`, `portal:sync-entra-users` |
| Entra ID Free SuperOps app assign | `entra_superops_app_id` (Application client ID) + `Application.Read.All` + `AppRoleAssignment.ReadWrite.All` |
| SuperOps requester display names | `EntraSyncDisplayName::formatSuperOpsFamilyName()` → `extensionAttribute1`; SCIM **name.familyName** Direct — [SuperOpsEntraSync.md#requester-display-names](SuperOpsEntraSync.md#requester-display-names) |
| Client onboarding wizard | `app/Services/ClientOnboardingService.php`, `admin/clients` create + edit |
| SuperOps API | `app/Services/SuperOps/SuperOpsApiClient.php` |
| Embedded support | `app/Http/Controllers/SupportController.php` |
| SSO launch | `app/Http/Controllers/Integrations/SuperOpsLaunchController.php` |
| Pax8 SSO launch | `app/Services/Pax8/Pax8SsoService.php`, `Integrations/Pax8LaunchController.php` |
| Portal links | `app/Models/PortalLink.php`, `ExternalServicesService.php` |
| Client Admin dashboard | `ClientAdminDashboardController`, `SuperOpsClientMetricsService`, `RefreshSuperOpsDashboardJob`, `HuntressClientMetricsService`, `RefreshHuntressSecurityJob` |
| M365 directory (async) | `M365DirectoryService`, `RefreshM365DirectoryJob` |
| M365 insights (async) | `M365InsightsService`, `RefreshM365InsightsJob` |
| Client roles | `App\Enums\UserRole`, `User` capability helpers, gates in `AuthServiceProvider` |

## Change Protocol

1. Read relevant Brain doc(s)
2. Implement code to match
3. **Update Brain** — same session/PR as the code change; do not defer documentation
4. Add or update ADRs in [Decisions.md](Decisions.md) for non-obvious choices (paths, integrations, security)

## Last Updated

| Date | Change |
|---|---|
| 2026-07-15 | Client Admin dashboard now uses a wide compact layout and automatically pre-warms every active client’s integration caches every ten minutes — [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md#10-configure-cron) |
| 2026-07-15 | Added Phase 2 M365 insights service layer: subscribed SKU inventory, cached utilisation summary, and unique background refresh — [ClientAdminDashboard.md](ClientAdminDashboard.md#microsoft-365-insights-phase-2-service-layer) |
| 2026-07-15 | Huntress Client Admin Phase 3 scaffold: `huntress_organization_id`, API client, metrics cache + `RefreshHuntressSecurityJob` — [ClientAdminDashboard.md](ClientAdminDashboard.md#huntress-security-metrics-phase-3-scaffold) |
| 2026-07-15 | Client Admin dashboard v2: system health / support / M365 sections; SuperOps SLA + open-ticket table; M365 insights; Huntress + Dropsuite scaffolds — [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Added Dropsuite / NinjaOne SaaS Backup Client Admin dashboard scaffold: client organization mapping, token API client, cached summary DTO/service, refresh job, and docs — [ClientAdminDashboard.md](ClientAdminDashboard.md#dropsuite--ninjaone-saas-backup-scaffold) |
| 2026-07-15 | SuperOps dashboard ticket queries must include `ticketId` or SuperOps returns empty rows — [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape) |
| 2026-07-15 | SuperOps Client Admin dashboard now mirrors the working Python API shape: bearer + `CustomerSubDomain`, unfiltered list calls, local `client.accountId` filtering — [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape) |
| 2026-07-15 | M365 directory refresh now reuses user license data and resolves SKU names with one tenant-level Graph request — [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Background refresh jobs use database queue + Plesk worker — [Deployment.md](Deployment.md#11-run-the-queue-worker), [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Client roles (`client_requester`, `client_billing_admin`, `client_admin`), Client Admin dashboard, async M365 directory — [ClientAdminDashboard.md](ClientAdminDashboard.md); Brain/README terminology aligned (`client_user` role → `client_requester`; pivot table `client_user` kept distinct) |
| 2026-07-14 | Client onboarding UI copy cleaned to short product language (Brain keeps policy docs) |
| 2026-07-14 | Client setup guide header shortened to product copy (policy lecture kept in Brain only) |
| 2026-07-14 | Migration resets legacy step 08 completions; every existing customer must complete the new Client SSO checklist |
| 2026-07-14 | Completed requester SSO redesign: checklist 08 now creates SuperOps Client SSO + customer-owned SAML app; old Accept UI/callback/config retired |
| 2026-07-14 | Added per-client SSO Application ID so Entra Free Sync can assign users without On IT guest accounts |
| 2026-07-14 | Retired failed Global SSO Multitenant adminconsent experiment and its AADSTS1003031/700016 recovery paths |
| 2026-07-14 | Docs: check Entra Overview License before tier |
| 2026-07-14 | MSP ownership rule: On IT technicians complete all customer-tenant onboarding/Accept actions through GDAP; customers do nothing |
| 2026-07-14 | Entra ID Free: portal Sync auto-assigns active licensed users to each customer Client SSO app after step 08 |
| 2026-07-14 | Live checklist: multi-block **Where** per app (Portal / SuperOps / Azure) on every step; Client SSO = **08** |
| 2026-07-14 | Restored full Entra click-by-click how-tos in live checklist (create app, mappings, Application IDs, group assignment) |
| 2026-07-14 | Free Application (client) ID path explicit in step 07; guide header highlights Azure steps 03–08 |
| 2026-07-14 | Live checklist rebuilt as 12 zero-training MSP steps; TechnicianTenantOnboarding is sole entry point; NewClientSetupGuide superseded |
| 2026-07-14 | Brain policy: update for **every** meaningful change (same turn) |
| 2026-06-25 | Security/reliability: signed admin consent state, block login for inactive clients, sync lock + throttle, partial-sync warnings, cron non-zero exit |
| 2026-06-25 | Sync now: per-user SCIM provision-on-demand + delay; spinner/status banner; Brain docs + ADR-020 aligned on name.familyName |
| 2026-06-25 | Sync now / dry run: spinner + status banner on Edit client while Entra sync runs |
| 2026-06-25 | Entra ID Free: `entra_superops_app_id` + `AppRoleAssignment.ReadWrite.All` — portal auto-assigns SuperOps app users |
| 2026-06-25 | SCIM setup: Bearer authentication + Entra ID Free workaround in checklist and Brain |
| 2026-06-24 | In-app checklist install-manual format (`OnboardingManual`); Brain docs step numbers aligned (05 consent, 06 SCIM, 07 SAML) |
| 2026-06-16 | MSP role labels on checklist (On IT technician — portal vs customer Entra) |
| 2026-06-23 | [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) — full new-customer guide; tenant sync + M365 directory |
| 2026-06-22 | SuperOps Technician SSO guide + ADR-013 update — [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) |
| 2026-06-19 | SuperOps technician SSO launch for team — [SuperOpsIntegration.md](SuperOpsIntegration.md) |
| 2026-06-19 | Pax8 SSO launch (`pax8_sso`, `/integrations/pax8/launch`, `clients.pax8_company_id`) — [Pax8Integration.md](Pax8Integration.md) |
| 2026-06-19 | In-app client setup wizard on Admin → Clients → Edit |
| 2026-06-19 | Two-sync model: Entra group sync (portal) + SuperOps SCIM; ADR-016; docs aligned |
| 2026-06-16 | Added [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) — shareable tenant onboarding timeline |
| 2026-06-16 | Added `Brain/design/` - synced marketing design system (SKILL, design-system, components) |
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
**Integrations:** SuperOps embedded support + SSO launch ([SuperOpsIntegration.md](SuperOpsIntegration.md)). Pax8 SSO launch ([Pax8Integration.md](Pax8Integration.md)). Requester SSO setup: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

**Local dev:** php artisan serve --host=localhost --port=8000 → http://localhost:8000/login
**Local setup:** Brain/LocalDevelopment.md (includes Windows PHP SSL/cacert.pem fix).

**Key routes:** /login, /auth/microsoft/callback, /dashboard, /support, /integrations/superops/launch, /integrations/pax8/launch, /admin/*
**Key code:** app/Http/Controllers/Auth/MicrosoftAuthController.php, app/Services/SuperOps/*, app/Services/Pax8/*

**Current state:** MVP built. Microsoft OAuth login works. SuperOps requester SSO is live in production at **`https://app.onit.ltd`**. `portal.onit.ltd` remains the SuperOps requester portal.

**SuperOps launch:** `SuperOpsSsoService` — client users only, `login_hint`, path `/#/requester/login`. Tests: `tests/Unit/SuperOpsSsoServiceTest.php`.

Do not commit .env. Do not develop from OneDrive-synced folders.
```
