# On IT Portal - Brain (Source of Truth)

Read these documents before changing application code. **Update Brain for every meaningful change in the same turn** (code, checklist, Entra/SSO, deploy, env) - not only “docs tickets”. This folder is the handover doc for technicians and maintainers.

## For Junior Engineers

1. Start here, then read [LocalDevelopment.md](LocalDevelopment.md) to run the app on Windows.
2. Read the doc for the area you are changing (auth, SuperOps, portal links, etc.).
3. **Every** code or config change that affects how the app or ops works → update the relevant Brain file **in the same change** (same PR/commit). No exceptions for “I’ll document later”.
4. If you hit an error locally, check [LocalDevelopment.md - Common Local Errors](LocalDevelopment.md#common-local-errors) first.

## Document Index

| Document | Purpose |
|---|---|
| [ProjectVision.md](ProjectVision.md) | Strategic goals and product positioning |
| [ProductRequirements.md](ProductRequirements.md) | MVP scope, user stories, functional requirements |
| [Architecture.md](Architecture.md) | Layers, flows, directory structure |
| [LocalDevelopment.md](LocalDevelopment.md) | **Windows dev setup, SSL fix, troubleshooting** |
| [DomainEmailChange.md](DomainEmailChange.md) | **Domain / primary email change** - object-id upsert, duplicate merge, SuperOps checks |
| [Authentication.md](Authentication.md) | Microsoft Entra ID login and sessions |
| [SuperOpsIntegration.md](SuperOpsIntegration.md) | Embedded support + SSO launch |
| [Pax8Integration.md](Pax8Integration.md) | **Pax8 SSO launch (dashboard tile → partner or company view)** |
| [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md) | **Pax8 Enterprise SSO for technicians (Primary Partner Admin, DNS, Finalize)** |
| [CustomerPortalSso.md](CustomerPortalSso.md) | **Customer SSO - portal + SuperOps requester + Pax8 access** |
| [Pax8CustomerAccess.md](Pax8CustomerAccess.md) | **Pax8 company view for clients (no customer IdP SSO)** |
| [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) | **Entra SAML setup for SuperOps requesters** |
| [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) | **Entra SAML setup for SuperOps technicians (On IT staff)** |
| [OperatorRunbook.md](OperatorRunbook.md) | **Step-by-step checklist - finish SSO, onboard users, production** |
| [ClientOnboarding.md](ClientOnboarding.md) | Master reference - platform values, verification, anti-patterns |
| [NewClientSetupGuide.md](NewClientSetupGuide.md) | **SUPERSEDED** - redirects to TechnicianTenantOnboarding |
| [ColleagueSetupGuide.md](ColleagueSetupGuide.md) | Simple guide for non-technical staff |
| [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) | **Only technician entry point** - mirrors live 12-step checklist |
| [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) | Deep narrative reference (not day-to-day start) |
| [AccessAndSync.md](AccessAndSync.md) | **Two-sync model: M365 → SuperOps SCIM + M365 → Portal** |
| [SuperOpsEntraSync.md](SuperOpsEntraSync.md) | **Sync 1: SuperOps SCIM** - Bearer auth, Entra ID Free, requester names `(User Mailbox)` / `(Shared Mailbox)` |
| [EntraGroupSync.md](EntraGroupSync.md) | **Sync 2: Portal Entra group sync** |
| [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) | **Complete re-do guide - permissions, group, SCIM, SAML, deploy, troubleshooting** |
| [PortalLinks.md](PortalLinks.md) | Link types and resolution |
| [DatabaseSchema.md](DatabaseSchema.md) | Tables, columns, tenant rules |
| [CustomerJourney.md](CustomerJourney.md) | End-to-end user flows |
| [UIUX.md](UIUX.md) | **Portal design system** (maps to marketing skill) |
| [design/README.md](design/README.md) | Canonical copy of onit.ltd SKILL, design-system, components |
| [Deployment.md](Deployment.md) | Plesk production, `.env`, Entra + SuperOps setup |
| [ServerOpsLog.md](ServerOpsLog.md) | **Running diary of production SSH / deploy actions** (recovery trail) |
| [ContactSupport.md](ContactSupport.md) | **Client Contact Support hub** - ticket, phone/hours, new starter |
| [Decisions.md](Decisions.md) | Architectural decision records |
| [Roadmap.md](Roadmap.md) | Phased future work |
| [ClientAdminDashboard.md](ClientAdminDashboard.md) | **Client Admin roles, SuperOps/Huntress metrics, async M365 directory and insights** |
| [UIOverhaul.md](UIOverhaul.md) | Client glance + Reports UI (merged to `main` 2026-08-18) |
| [UsecureIntegration.md](UsecureIntegration.md) | **usecure - design only for later** (not near-term; blocked on beta API keys) |

## Implementation Map

| Brain topic | Code |
|---|---|
| Entra login | `app/Http/Controllers/Auth/MicrosoftAuthController.php` |
| Entra group sync | `app/Services/EntraSync/EntraGroupSyncService.php`, `portal:sync-entra-users` |
| Domain / SuperOps email align | `SuperOpsUserSyncService::alignRequesterPrimaryEmails`, [DomainEmailChange.md](DomainEmailChange.md) |
| Entra ID Free SuperOps app assign | `entra_superops_app_id` (Application client ID) + `Application.Read.All` + `AppRoleAssignment.ReadWrite.All` |
| SuperOps requester display names | `EntraSyncDisplayName::formatSuperOpsFamilyName()` → `extensionAttribute1`; SCIM **name.familyName** Direct - [SuperOpsEntraSync.md#requester-display-names](SuperOpsEntraSync.md#requester-display-names) |
| Client onboarding wizard | `ClientOnboardingService`, `OnboardingManual` (automated / remaining / recovery), `admin/clients` forms + wire/Apply SCIM |
| Shared client visibility | `ClientVisibilityService` - org-wide vs own person across SuperOps / M365 / Huntress |
| Huntress cases + staff board | `HuntressSecurityController`, `ClientHuntressSecurityController`, `HuntressIncidentService` |
| SuperOps API | `app/Services/SuperOps/SuperOpsApiClient.php`, `SuperOpsTicketService` createTicket contract - [SuperOpsIntegration.md](SuperOpsIntegration.md#createticket-contract-all-clients) |
| Embedded support | `app/Http/Controllers/SupportController.php` |
| SSO launch | `app/Http/Controllers/Integrations/SuperOpsLaunchController.php` |
| Pax8 SSO launch | `app/Services/Pax8/Pax8SsoService.php`, `Integrations/Pax8LaunchController.php` |
| Portal links | `app/Models/PortalLink.php`, `ExternalServicesService.php` |
| Client Admin dashboard | `ClientAdminDashboardController`, `SuperOpsClientMetricsService`, `RefreshSuperOpsDashboardJob`, `HuntressClientMetricsService`, `RefreshHuntressSecurityJob` |
| Technician Integration Health | `IntegrationHealthController`, `/admin/integration-health` |
| M365 directory (async) | `M365DirectoryService`, `RefreshM365DirectoryJob` |
| M365 insights (async) | `M365InsightsService`, `RefreshM365InsightsJob` |
| Client roles | `App\Enums\UserRole`, `User` capability helpers, gates in `AuthServiceProvider` |
| usecure (planned) | Not implemented - [UsecureIntegration.md](UsecureIntegration.md); clone Huntress/Dropsuite when keys land |

## Change Protocol

1. Read relevant Brain doc(s)
2. Implement code to match
3. **Update Brain** - same session/PR as the code change; do not defer documentation
4. Add or update ADRs in [Decisions.md](Decisions.md) for non-obvious choices (paths, integrations, security)

## Last Updated

| Date | Change |
|---|---|
| 2026-08-24 | Ticket show: do not select SuperOps `description` (field does not exist); opening text from conversation list - [SuperOpsIntegration.md](SuperOpsIntegration.md#ticket-detail-getticket) |
| 2026-08-24 | Portal ticket create is MSP-wide: `source` INTEGRATION + mandatory `requestType` Incident; GraphQL client surfaces SuperOps `clientError` - [SuperOpsIntegration.md](SuperOpsIntegration.md#createticket-contract-all-clients) |
| 2026-08-24 | **Contact Support** hub for client users (nav next to Services): log ticket, phone/hours, new starter → SuperOps - [ContactSupport.md](ContactSupport.md) |
| 2026-08-24 | Client portal **Beta** banner (link to Service Desk / support create); M365 **Excel + CSV** download (licences then users) - [ClientAdminDashboard.md](ClientAdminDashboard.md), [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-24 | Confirmed laptop / GitHub / live all on `b40dcef` before feature work - [ServerOpsLog.md](ServerOpsLog.md) |
| 2026-08-21 | Server ops log + Cursor rule: document every production SSH/deploy in [ServerOpsLog.md](ServerOpsLog.md) |
| 2026-08-19 | Dashboard service cards (Support, M365, Security, Backup) are equal height - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-19 | Support & Devices restart pager stays inside the same card chrome as the other device tiles |
| 2026-08-18 | M365 directory: friendly licence names + wrapping chips; names no longer duplicate mailbox type - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-18 | **Mobile overhaul:** bottom tab nav, slide-up menu, glance/org ticket cards, scroll strips - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-13 | **Incident + fix:** SCIM Entra apps must use non-gallery **template instantiate** (not POST /applications); 0 templates → Retry Graph auto-recreate; step 07 **Failed**; IH SuperOps SCIM column; YorPower OK / MXVI ready for Apply - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-13 | Docs: M365/Entra automation in portal only; SuperOps console for tokens + SSO paste - [ClientOnboarding.md](ClientOnboarding.md) |
| 2026-08-13 | **Retry Graph setup** auto-deletes/recreates broken SuperOps SCIM Entra app (0 templates) - then Apply SCIM only - [ClientOnboarding.md](ClientOnboarding.md) |
| 2026-08-13 | Checklist step **07** shows **Failed** (not Done) when live Entra SCIM export is unhealthy; cleaner SCIM alert + single Retry - [ClientOnboarding.md](ClientOnboarding.md), [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-13 | Integration Health **SuperOps SCIM** column - Entra export health next to SuperOps API feed; failed/setup in KPI + notices - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-13 | SCIM job health/repair (`portal:repair-superops-scim`), Edit Client banner, provision missing SuperOps users on Sync 2 - [SuperOpsEntraSync.md](SuperOpsEntraSync.md), [AccessAndSync.md](AccessAndSync.md) |
| 2026-08-11 | Docs pass: domain cutover (async Sync result, SuperOps align paths), UIOverhaul live-on-branch status, Roadmap - [DomainEmailChange.md](DomainEmailChange.md), [UIOverhaul.md](UIOverhaul.md), [Roadmap.md](Roadmap.md) |
| 2026-08-11 | Glance/Reports: **Last month** locked + visible hint when MoM history not ready - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-11 | Integration Health: idle “due” window no longer reads as outage; prewarm card clarifies last-run queue counts; adaptive minutes in notices - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-11 | SuperOps identity: alias match + superops_user_id bind + unmatched Sync reporting - [DomainEmailChange.md](DomainEmailChange.md) |
| 2026-08-11 | M365 SOT: Entra Sync **aligns SuperOps requester emails** to portal/Graph primary + object-id portal upsert - [DomainEmailChange.md](DomainEmailChange.md) |
| 2026-08-11 | Portal Entra identity: **object-id-first** upsert + primary email update + duplicate merge; domain cutover runbook - [DomainEmailChange.md](DomainEmailChange.md), [EntraGroupSync.md](EntraGroupSync.md) |
| 2026-08-10 | Staff: **What the client sees at home** on Edit Client + Clients/admin copy so product-mix dashboards are not “forgotten” - [ClientAdminDashboard.md](ClientAdminDashboard.md), [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Glance/Reports: no empty “Threats stopped” when MDR not sold; Huntress/Dropsuite labelled **Add-on** with support-still-helps copy - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Admin **Graph re-consent** page (batch Accept links for all linked tenants) - [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-10 | Bootstrap remaining-work warnings honour SCIM/SSO checklist; `portal:graph-reconsent-urls`; guide Re-consent CTA - [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-10 | Secure Score + MFA Graph Application perms (`SecurityEvents` / `AuditLog` / `Reports.Read`) + re-consent ops §0.3a - [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-10 | Customer dashboard gap close (activity, waiting-on-you, Huntress MTD, Secure Score/MFA soft-fail, nightly MoM snapshots) - [UIOverhaul.md](UIOverhaul.md), [DatabaseSchema.md](DatabaseSchema.md) |
| 2026-08-10 | Organisation overview visual revolve: dense grid + glance card language - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-10 | SuperOps traffic lights: **drop offline devices** (field/night offline-by-design); drive by SLA + ticket backlog - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Traffic lights: client-facing **why** sentence (`status_reason`) on cards/hero/reports - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Glance traffic lights: Healthy/Issues/Critical bands per feed + worst-case hero - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Reports: drop dual logo in rail (nav already brands); full-width shell; mobile stack + 44px targets - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Reports **CSS Grid** layout fix (Tailwind flex purge shoved main off-screen); glance live metrics only - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | UI polish on **UIOverhaul**: reports rail/main layout (inline CSS); compact “Not set up” on glance - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Integration Health false schedule-lock panic fixed; UI experiment docs on main + branch - [ClientAdminDashboard.md](ClientAdminDashboard.md), [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | Client home “at a glance” + Reports on branch **UIOverhaul** (mockup 1a/1c) - [UIOverhaul.md](UIOverhaul.md) |
| 2026-08-10 | usecure integration **design only**: sold-some clients, MSP tenant like Huntress, request beta GraphQL key from support, full modular build plan - [UsecureIntegration.md](UsecureIntegration.md) |
| 2026-08-10 | Integration Health prewarm card: label SuperOps/other **jobs queued** (was misleading “critical N”) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-10 | Priorities 1-5: Roadmap/PRD as shipped+backlog; sold coverage on admin dashboard; cold-optional prewarm; support SSO-first threads; IH/`cacheKey()` discipline - [Roadmap.md](Roadmap.md), [ProductRequirements.md](ProductRequirements.md), ADR-022/023 |
| 2026-08-10 | Integration Health Dropsuite “Never loaded” false alarm: read current `cacheKey()` (v3) not only v2 - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | M365 paid-util free/trial heuristics + licensed-users label; Dropsuite 24h summary + Online backups page - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Dropsuite protected-mailbox list paginated (10/page) on Organisation + staff View Dropsuite - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Client users get **My Systems** nav (not Organisation); same `/client-admin` personal feed - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | System health tiles: dynamic 3-per-row layout; Client Admin not-sold upsell copy; requesters only live tiles - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | SCIM familyName: no [surname]/expression fallback - Direct extensionAttribute1 only; hybrid uses SuperOps API writes - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | Hybrid SuperOps names: Graph extensionAttribute1 fail → SuperOps API first/last push; SCIM expression drops plain [surname] fallback - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | Apply SCIM runs as encrypted `high` queue job (no nginx 504 on Graph lag) - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | Apply SCIM: stop disabling inputs on submit (browser omitted paste; false “fields required”) - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | YorPower Dropsuite: correct map is org **`6182`** not user-id; cold refresh failures mark `last_result` failed - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Client Admin Dropsuite: full mailbox list; requester My backup only - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Staff View Dropsuite on Edit client; Integration Health jobs show resolved client names - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Apply SCIM reuses Entra job (longer Graph lag wait); no multi-hour “clear”; progress = button/step 07/Entra Provisioning/Last synced - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | Step 07 Apply SCIM: green only when name mappings + Sync queued; SuperOps bulk outside Entra warns in checklist - [SuperOpsEntraSync.md](SuperOpsEntraSync.md), [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| 2026-08-07 | Integration Health: Never loaded (cold) sold feeds flag warning headline/notices - not silent under green SuperOps cells - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-07 | Post-deploy always `chown` storage after artisan as root - fixes **500 Permission denied** on compiled views (`/dashboard`) - [Deployment.md](Deployment.md) |
| 2026-08-07 | Apply SCIM waits/polls Graph until job schema is ready before name.familyName mapping (fix first-apply ProvisioningTaskNotFound on new apps) - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-07 | Bootstrap: save P1 immediately; continue SuperOps apps if group 403; Edit form warns stale Free overwrite - [ClientOnboarding.md](ClientOnboarding.md) |
| 2026-08-07 | Connect Microsoft: single Accept CTA + **Retry Graph setup**; bootstrap waits/retries Graph after Accept (IdentityNotFound lag) - [ClientOnboarding.md](ClientOnboarding.md), [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| 2026-08-06 | Product entitlements: sold vs mapped per client; CA contact AM when setup needed; Huntress/Dropsuite branch retired - [ClientAdminDashboard.md](ClientAdminDashboard.md), [DatabaseSchema.md](DatabaseSchema.md) |
| 2026-08-06 | Unlinked integrations (superseded by entitlements): Client Admin “contact AM”; requesters hide unmapped - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-06 | Production deploy is **GitHub → Plesk bare mirror (`laravel_af3fd1`) → `app.onit.ltd`**, branch **main** - [Deployment.md](Deployment.md#updating-the-application) |
| 2026-08-06 | SuperOps dashboard stuck “Getting old”: GraphQL `requester`/`client` are leaf JSON - no sub-selection; decode in PHP - [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape) |
| 2026-08-06 | Dropsuite prod: code `52bcac9` live; env scaffold disabled; tokens + client org maps still required for enable - [ClientAdminDashboard.md](ClientAdminDashboard.md#dropsuite--ninjaone-saas-backup) |
| 2026-08-06 | Visibility: Technician Admin (all customers) ≠ Client Admin (own customer only) ≠ requester personal - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-06 | Dropsuite aligned to sub-reseller PDF (`GET /accounts`); Client Admin org backups vs requester last-run only - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-06 | Integration Health: What's going on summary mirrors Refresh timing drawer (same DB settings + live unsaved preview) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-06 | Night ship: all 12 onboarding steps automation-first; Client SSO wire wording; Huntress/visibility/bootstrap SP wait - [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md), [ClientAdminDashboard.md](ClientAdminDashboard.md), [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-05 | Huntress security cases: active/resolved list + detail for Client Admin (own org) and staff with client access - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health per-client table: Huntress + Dropsuite columns via `FEED_COLUMNS` (same refresh pipeline as SuperOps/M365) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Modular dashboard feeds: `DashboardFeed` contract + registry (SuperOps/Huntress/Dropsuite/M365); feed tile partials; agentic “add feed” checklist - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Security feeds: Huntress + Dropsuite adaptive requeue, Integration Health columns, dual Dropsuite auth headers, probe command - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health adaptive model documented (hot/idle/hours); sticky admin nav; timing drawer - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Admin shell: sticky sidebar `z-30` + more Integration Health spacing (nav no longer under content) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health spacing/scroll polish: no white plate title, status as accent line, drawer scroll + soft inputs - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health: refresh timing as right drawer (live view stays primary); no in-page mega-form - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health: collapsible auto-refresh settings, tighter field spacing, mobile card layouts - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health timing form redesign + `portal:ensure-freshness-settings` (defaults once, no overwrite) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Auto-refresh timing: **DB/admin only** (removed portal_freshness from `.env`) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health: **Auto-refresh timing** admin form (all clients, DB settings) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Adaptive refresh: customer portal sessions → 2.5m; idle → hourly (UK 07-19 context) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | **2.5-minute** refresh cadence (prewarm + Entra + requeue); soft windows ~5m - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | **5-minute cadence**: prewarm requeue + Entra schedule every 5m; soft windows ~10m - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | M365 people/licences prewarm requeue at 10m (was 15m = always “Getting old” after prewarm lag) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Prod ops: sick `cron` (huge RSS / skipped minutes) starved hourly Entra; catch-up + **systemd timers** for schedule/queue; absolute cron log paths - [Deployment.md](Deployment.md) |
| 2026-08-05 | Prewarm stall: stuck schedule `cache_locks` / `onOneServer` removed; scheduler tick; plain-English Integration Health - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | Integration Health own Staff Admin sidebar tab + live fragment; portal nav Organisation / Staff Admin labels - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health pipeline visibility: prewarm heartbeat, jobs table, flags, due/aging/stuck blockers, auto notices - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Client vs technician refresh messaging; SuperOps requeue at 10m before 15m client window (stops ~20m “always stale”); Integration Health **aging** - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Integration Health reads M365 licence cache v3 (was stuck showing stale v2 timestamps) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | SuperOps stale refresh always prewarms; clear orphaned refresh_queued; workers max-time 55s (no stack); freshness 15m banner - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | Temp/debug scripts only in top-level `tmp/` (gitignored) - never under `app/` or `storage/` - [LocalDevelopment.md](LocalDevelopment.md) |
| 2026-08-05 | M365 utilisation excludes preview/IW pools (e.g. Project Madeira 10k seats); disambiguate duplicate Business Premium SKUs - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | M365 directory: live panel poll (no full-page reload); keep cached tables while refreshing; avoid deploy `cache:clear` wiping snapshots - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | Integration Health clears orphaned `refresh_queued` cache when `jobs` is empty (no more phantom “queued”) - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Admin Integration Health live-polls every 5s; Entra sync marked queued at dispatch so Active/Stuck appears immediately - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | Admin Integration Health dashboard (all clients last refresh / stuck jobs); dual workers so one client cannot block others - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-05 | M365 directory / Client Admin: auto-reload UI while background refresh in progress - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-05 | M365 Client Admin: exclude free/bulk licences from utilisation %; show friendly product names - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-08-04 | Dashboard always pre-filled: cold SuperOps never skipped on deep queue; `high` worker queue; no page-view stampede - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md) |
| 2026-08-04 | Scrub real customer names from live steps product copy and Brain runbook examples (use `{Company}` placeholders only); SuperOps names after background Sync (minutes) |
| 2026-08-04 | Apply SCIM name-mapping fix: Graph `attributeMapping` has no `mappingType` (400 RequestParameterInvalid); remove customer examples from product-facing SCIM copy - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-04 | Apply SCIM now sets SuperOps name mappings (familyName ← extensionAttribute1) and queues portal Sync so requesters get (User Mailbox)/(Shared Mailbox) - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-04 | Entra tier detection treats Microsoft 365 Business Premium / SPB as P1 (includes Entra ID P1); SuperOps names require Sync now + SCIM `name.familyName`←extensionAttribute1 - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-04 | SCIM Apply + Client SSO Configure forms moved into checklist steps 07/08 (not left column only) - [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| 2026-08-04 | Edit Client **Configure SAML in Entra**: paste SuperOps Entity ID + ACS once; Graph sets SAML mode/URLs/cert; shows Login URL + certificate for SuperOps paste - [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) |
| 2026-08-04 | Edit Client **Apply SCIM credentials + start**: paste SuperOps Tenant URL + secret once; Graph writes Entra provisioning secrets and starts job (`Synchronization.ReadWrite.All`); secret not stored - [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md), [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-08-04 | Connect bootstrap: create SuperOps Entra apps via `POST /applications` + wait/retry (fix Graph 404 on app role after template instantiate); save Application (client) IDs even if role patch lags - [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-04 | Edit Client: checklist **Mark this step complete** auto-saves on tick; clarified Save client (left) vs checklist (right) - does not share one form - [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| 2026-08-04 | Connect Microsoft tenant bootstrap after Accept: auto tenant ID, Free/P1, portal group, SuperOps SCIM + Client SSO Entra apps; adds Graph Group.ReadWrite.All + Application.ReadWrite.All - [ClientOnboarding.md](ClientOnboarding.md), [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) |
| 2026-08-04 | Onboarding checklist Azure paths: private browser + land on customer tenant directly (no On IT→switch); Entra **Manage** before Groups / Enterprise apps - [ClientOnboarding.md](ClientOnboarding.md), [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) |
| 2026-07-17 | Reliability pass: skip/batch mailboxSettings N+1; hourly Entra sync queues per-client jobs; dry-run queued; job timeouts; shorter refresh_queued flags; SuperOps page cap; prewarm skips when queue deep - [Deployment.md](Deployment.md#10-configure-cron), [EntraGroupSync.md](EntraGroupSync.md) |
| 2026-07-17 | Entra SCIM provision-on-demand kept, but fixed hang: only changed/newly-assigned users are provisioned, and provision runs via `ProvisionSuperOpsScimUsersJob` so hourly sync can finish - [SuperOpsEntraSync.md](SuperOpsEntraSync.md) |
| 2026-07-17 | Added missing `failed_jobs` table migration - production queue was processing jobs but failed-job tooling errored without the table - [Deployment.md](Deployment.md#11-run-the-queue-worker) |
| 2026-07-17 | Production cron must use root crontab over SSH - Plesk UI Scheduled Tasks fail with `ld.so` on `/opt/plesk/php/8.3/bin/php`; keep scheduler + queue worker entries in root crontab - [Deployment.md](Deployment.md#10-configure-cron) |
| 2026-07-15 | Microsoft 365 Directory page now uses the same wide (`96rem`) layout as the Client Admin dashboard so its people/groups tables are not squished - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Client Admin dashboard now uses a wide compact layout and automatically pre-warms every active client’s integration caches every ten minutes - [ClientAdminDashboard.md](ClientAdminDashboard.md), [Deployment.md](Deployment.md#10-configure-cron) |
| 2026-07-15 | Added Phase 2 M365 insights service layer: subscribed SKU inventory, cached utilisation summary, and unique background refresh - [ClientAdminDashboard.md](ClientAdminDashboard.md#microsoft-365-insights-phase-2-service-layer) |
| 2026-07-15 | Huntress Client Admin Phase 3 scaffold: `huntress_organization_id`, API client, metrics cache + `RefreshHuntressSecurityJob` - [ClientAdminDashboard.md](ClientAdminDashboard.md#huntress-security-metrics-phase-3-scaffold) |
| 2026-07-15 | Client Admin dashboard v2: system health / support / M365 sections; SuperOps SLA + open-ticket table; M365 insights; Huntress + Dropsuite scaffolds - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Added Dropsuite / NinjaOne SaaS Backup Client Admin dashboard scaffold: client organization mapping, token API client, cached summary DTO/service, refresh job, and docs - [ClientAdminDashboard.md](ClientAdminDashboard.md#dropsuite--ninjaone-saas-backup-scaffold) |
| 2026-07-15 | SuperOps dashboard ticket queries must include `ticketId` or SuperOps returns empty rows - [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape) |
| 2026-07-15 | SuperOps Client Admin dashboard now mirrors the working Python API shape: bearer + `CustomerSubDomain`, unfiltered list calls, local `client.accountId` filtering - [ClientAdminDashboard.md](ClientAdminDashboard.md#superops-graphql-request-shape) |
| 2026-07-15 | M365 directory refresh now reuses user license data and resolves SKU names with one tenant-level Graph request - [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Background refresh jobs use database queue + Plesk worker - [Deployment.md](Deployment.md#11-run-the-queue-worker), [ClientAdminDashboard.md](ClientAdminDashboard.md) |
| 2026-07-15 | Client roles (`client_requester`, `client_billing_admin`, `client_admin`), Client Admin dashboard, async M365 directory - [ClientAdminDashboard.md](ClientAdminDashboard.md); Brain/README terminology aligned (`client_user` role → `client_requester`; pivot table `client_user` kept distinct) |
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
| 2026-07-14 | Free Application (client) ID path explicit in step 07; guide header highlights Azure steps 03-08 |
| 2026-07-14 | Live checklist rebuilt as 12 zero-training MSP steps; TechnicianTenantOnboarding is sole entry point; NewClientSetupGuide superseded |
| 2026-07-14 | Brain policy: update for **every** meaningful change (same turn) |
| 2026-06-25 | Security/reliability: signed admin consent state, block login for inactive clients, sync lock + throttle, partial-sync warnings, cron non-zero exit |
| 2026-06-25 | Sync now: per-user SCIM provision-on-demand + delay; spinner/status banner; Brain docs + ADR-020 aligned on name.familyName |
| 2026-06-25 | Sync now / dry run: spinner + status banner on Edit client while Entra sync runs |
| 2026-06-25 | Entra ID Free: `entra_superops_app_id` + `AppRoleAssignment.ReadWrite.All` - portal auto-assigns SuperOps app users |
| 2026-06-25 | SCIM setup: Bearer authentication + Entra ID Free workaround in checklist and Brain |
| 2026-06-24 | In-app checklist install-manual format (`OnboardingManual`); Brain docs step numbers aligned (05 consent, 06 SCIM, 07 SAML) |
| 2026-06-16 | MSP role labels on checklist (On IT technician - portal vs customer Entra) |
| 2026-06-23 | [NewCustomerTenantSetup.md](NewCustomerTenantSetup.md) - full new-customer guide; tenant sync + M365 directory |
| 2026-06-22 | SuperOps Technician SSO guide + ADR-013 update - [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md) |
| 2026-06-19 | SuperOps technician SSO launch for team - [SuperOpsIntegration.md](SuperOpsIntegration.md) |
| 2026-06-19 | Pax8 SSO launch (`pax8_sso`, `/integrations/pax8/launch`, `clients.pax8_company_id`) - [Pax8Integration.md](Pax8Integration.md) |
| 2026-06-19 | In-app client setup wizard on Admin → Clients → Edit |
| 2026-06-19 | Two-sync model: Entra group sync (portal) + SuperOps SCIM; ADR-016; docs aligned |
| 2026-06-16 | Added [TechnicianTenantOnboarding.md](TechnicianTenantOnboarding.md) - shareable tenant onboarding timeline |
| 2026-06-16 | Added `Brain/design/` - synced marketing design system (SKILL, design-system, components) |
| 2026-06-16 | Removed Acme/Globex/Initech demo seed defaults; added `portal:purge-demo-data` cleanup command |
| 2026-06-16 | Added production security hardening: HTTPS forced in app + default security headers middleware |
| 2026-06-15 | Production Laravel URL set to `app.onit.ltd` (`portal.onit.ltd` = SuperOps only) |
| 2026-06-15 | SuperOps launch: direct redirect to `/#/requester/login` (removed interim instructions page) |
| 2026-06-15 | Documented IDP Login URL source (Entra Section 4), URL placement table, Step 2 Save requirement |
| 2026-06-15 | Fix SuperOps launch: do not redirect to Entra SAML URL directly (`AADSTS750054`) |
| 2026-06-12 | On IT Technology Partners client + `portal.test@onit.ltd` seeder; M365 sync deferred to Phase 2 (ADR-014) |
| 2026-06-12 | Added [ClientOnboarding.md](ClientOnboarding.md) - master new client/user checklist, On IT SAML values |
| 2026-06-12 | Added [OperatorRunbook.md](OperatorRunbook.md) - phased operator checklist |
| 2026-06-12 | SuperOps guide: provisioning at scale, groups vs manual, multi-tenant Client SSO note |
| 2026-06-12 | SuperOps Requester SSO guide updated with On IT Consumer Service URL (`portal.onit.ltd`) |
| 2026-06-12 | Added [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md) - requester-only Entra SAML guide |
| 2026-06-12 | Documented Entra app split: portal (OAuth) vs SuperOps requester/technician (SAML) |
| 2026-06-12 | SuperOps launch uses requester portal URL (`{subdomain}.superops.ai`); removed broken `/login/sso` fallback |
| 2026-06-12 | Dashboard simplified to SuperOps + Pax8 only; portal link seeder syncs and dedupes |
| 2026-06-12 | Added `remember_token` to users table - fixes login 500 after Microsoft OAuth callback |
| 2026-06-12 | Added [LocalDevelopment.md](LocalDevelopment.md) - Windows path, SQLite, PHP SSL/cURL error 60 fix |

## Session Handoff (paste into a new chat)

```
You are the senior engineer on the On IT Portal project.

**Canonical project path:** C:\Dev\OnIT-Portal
(Mirror copy synced to OneDrive workspace - develop from C:\Dev only.)

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

**SuperOps launch:** `SuperOpsSsoService` - client users only, `login_hint`, path `/#/requester/login`. Tests: `tests/Unit/SuperOpsSsoServiceTest.php`.

Do not commit .env. Do not develop from OneDrive-synced folders.
```
