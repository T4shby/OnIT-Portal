# On IT Portal — Architectural Decision Records

## ADR-001

Date: 2026-06-09

Decision:
Use Laravel 11 as the application framework.

Reason:
Strong PHP ecosystem, rapid development, excellent authentication support via Socialite, built-in authorisation (policies/gates), Eloquent ORM for multi-tenant queries, and straightforward deployment to Plesk environments.

Consequences:
Requires Composer and PHP 8.2+ on the server. Team must maintain Laravel version updates.

---

## ADR-002

Date: 2026-06-09

Decision:
Use multi-tenant Microsoft Entra ID authentication via the `organizations` authority endpoint.

Reason:
On IT serves multiple client organisations, each with their own Microsoft 365 tenant. A multi-tenant app registration allows users from any client tenant to authenticate with their work/school accounts. The `organizations` endpoint excludes personal Microsoft accounts.

Consequences:
Users must be pre-provisioned in the portal before first login (email match). No self-registration. App registration must be configured as multi-tenant in Azure.

---

## ADR-003

Date: 2026-06-09

Decision:
Use database-backed sessions instead of file-based sessions for production.

Reason:
File sessions do not scale across multiple PHP-FPM workers reliably and are harder to manage on Plesk. Database sessions provide persistent, queryable session storage suitable for 50 concurrent users.

Consequences:
Adds a `sessions` table to the database. Redis may be adopted later for improved performance at higher scale.

---

## ADR-004

Date: 2026-06-09

Decision:
Implement tenant isolation via client_id foreign keys, global scopes, policies, and middleware.

Reason:
Multi-tenant data isolation is critical for an MSP portal. Three-layer enforcement (database, application, authorisation) provides defence in depth against cross-client data exposure.

Consequences:
All client-facing queries must include tenant scope. Admin roles require special handling to access multiple clients. Slightly more complex query logic.

---

## ADR-005 (superseded by ADR-011)

Date: 2026-06-09 — Superseded 2026-06-12

---

## ADR-011

Date: 2026-06-12

Decision:
Integrate SuperOps as first-class partner (embedded support + SSO launch). Pax8 uses the same launch-route pattern (`pax8_sso`); Pax8 API automation is Phase 3.

Reason:
Business requirement for true SSO — one Microsoft login, support without second credential.

Consequences:
SuperOps services, migration, support routes, Brain/SuperOpsIntegration.md. Pax8: `Pax8SsoService`, `Pax8LaunchController`, `clients.pax8_company_id`, Brain/Pax8Integration.md.

---

## ADR-006

Date: 2026-06-09

Decision:
Use Blade templates with Tailwind CSS and Alpine.js instead of a SPA framework.

Reason:
Aligns with the tech stack requirements. Blade provides server-rendered pages that are fast, SEO-friendly, and simple to deploy. Tailwind enables rapid UI development. Alpine.js handles lightweight interactivity without the complexity of React or Vue.

Consequences:
No client-side routing or state management. Page transitions are full server round-trips. Acceptable for an internal portal with 50 concurrent users.

---

## ADR-007

Date: 2026-06-09

Decision:
Use four roles: super_admin, account_manager, client_admin, client_user.

Reason:
Covers the organisational hierarchy: platform administrators, On IT account managers, client IT leads, and client end users. Each role has clearly defined access boundaries.

Consequences:
Role checks required on every route and policy. Account managers use pivot table `client_user` for multi-client access. Role enum stored as string in database.

**Superseded (2026-07-15) by [ADR-021](#adr-021):** customer-facing `client_user` renamed to `client_requester`; added `client_billing_admin`. Staff roles (`super_admin`, `account_manager`) and pivot table `client_user` are unchanged.

---

## ADR-008

Date: 2026-06-09

Decision:
Pre-provision users via admin interface; no self-registration.

Reason:
MSP portal must control who has access. Allowing any Microsoft user to register would break tenant isolation and security model.

Consequences:
Administrators must create user records before clients can log in. Onboarding workflow includes admin creating client + users, then notifying client of portal URL.

---

## ADR-009

Date: 2026-06-09

Decision:
Store portal link URLs in the database, seeded from environment variables.

Reason:
Allows administrators to override default URLs via admin UI without redeploying. Environment variables provide sensible defaults for initial setup.

Consequences:
Two sources of truth for URLs (env and database). Seeder populates from env on first run; subsequent changes via admin UI only.

---

## ADR-010

Date: 2026-06-09

Decision:
Use activity_logs table for audit trail instead of a third-party logging package.

Reason:
Simple, purpose-built audit log meets MVP requirements. Logs user actions on sensitive operations with subject polymorphism. No external dependency needed.

Consequences:
Log retention and rotation must be managed manually or via scheduled task in future. No advanced log analytics in MVP.

---

## ADR-012

Date: 2026-06-12

Decision:
Develop from `C:\Dev\OnIT-Portal` on Windows; use SQLite for local database; configure PHP CA bundle for outbound HTTPS.

Reason:
OneDrive-synced paths block Laravel writes to `storage/` and `bootstrap/cache`. Local MySQL is optional overhead for MVP work. WinGet PHP on Windows does not set `curl.cainfo` / `openssl.cafile`, which breaks Microsoft OAuth token exchange (cURL error 60).

Consequences:
Documented in [LocalDevelopment.md](LocalDevelopment.md). Juniors must not clone into OneDrive Desktop. Production remains MySQL on Plesk with OS-managed certificates. Re-apply `php.ini` CA settings after PHP upgrades via WinGet.

---

## ADR-013 (updated 2026-07-14)

Date: 2026-06-12 — updated 2026-07-14

Decision:
Configure **SuperOps Requester Client SSO** through a dedicated SAML enterprise app in each customer's Entra tenant, and **SuperOps Technician SSO** through the separate On IT technician SAML app. Technicians launch to `/#/technician/login` on the same SuperOps host as requesters (`portal.onit.ltd`), not `app.superops.ai`. The portal redirects only; SuperOps SPA initiates SP-initiated SAML.

Reason:
Customer identities must remain in their own tenants without On IT B2B guests. SuperOps Client SSO provides customer-specific Entity IDs and Consumer Service URLs. The retired shared Global SSO Multitenant admin-consent experiment was unsupported and conflicted with Azure verified-domain identifier rules.

Consequences:
Each customer has separate SCIM and requester Client SSO applications in its own tenant. The customer Login URL and certificate are stored in that customer's SuperOps Client SSO configuration, not portal `.env`. Portal `.env` uses `SUPEROPS_REQUESTER_PORTAL_URL` for both roles with different hash paths. Setup guides: [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md), [SuperOpsTechnicianSsoSetup.md](SuperOpsTechnicianSsoSetup.md).

---

## ADR-014

Date: 2026-06-12

Decision:
Defer M365/Entra ↔ portal user sync to Phase 2. MVP uses manual Admin → Users provisioning; `is_active` is not tied to Entra group membership or M365 account lifecycle.

Reason:
SCIM or Graph-based sync requires per-client Entra group design, API permissions, and conflict handling. Manual provisioning is sufficient for Phase A testing and early clients.

Consequences:
Superseded for portal users by **ADR-016** (Entra group sync built). See [ClientOnboarding.md](ClientOnboarding.md) and in-app setup wizard on Admin → Clients → Edit.

---

## ADR-015

Date: 2026-06-15

Decision:
Launch SuperOps at `/#/requester/login` (not `/#/login` or the invalid `/#/login/requester`). Verified from SuperOps production JS bundle route table.

Reason:
We initially used `/#/login/requester`, which is not a registered SuperOps route — it showed the role chooser. The correct SPA path is `/#/requester/login`.

Consequences:
Portal default `SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login`. Users should go straight to requester SSO without clicking the chooser.

---

## ADR-016

Date: 2026-06-19

Decision:
Implement **two independent syncs** from one M365 security group per customer: (1) SuperOps native Entra SCIM per client for requesters; (2) portal `portal:sync-entra-users` via Microsoft Graph Application permissions. Do **not** provision SuperOps requesters from portal code.

Reason:
SuperOps already provides per-client SCIM endpoints (Integrations → Entra ID). A custom portal SuperOps API provisioner duplicated that leg and added failure modes. M365 remains the single source of truth; each system syncs its own users.

Consequences:
Supersedes the deferred-sync intent in ADR-014 for portal users. SuperOps requesters are SCIM-managed, not manual (when SCIM is configured). Manual portal users (`provisioned_by = manual`) are skipped by sync. See [AccessAndSync.md](AccessAndSync.md), [EntraGroupSync.md](EntraGroupSync.md), [SuperOpsEntraSync.md](SuperOpsEntraSync.md).

---

## ADR-017

Date: 2026-06-19

Decision:
Pax8 technician launch uses `PAX8_PARTNER_PORTAL_URL` + `PAX8_PARTNER_LOGIN_PATH` (default `/login`) with `login_hint`. True Microsoft SSO requires Pax8 **Enterprise SSO (Azure AD)** configured in Pax8 admin — the portal cannot bypass Auth0's identifier step or force Azure redirect like SuperOps SAML.

Reason:
Pax8 partner auth runs through Auth0 at `login.pax8.com`. `login_hint` pre-fills email on the identifier screen but does not complete SSO. Pax8's Enterprise SSO PDF requires users to authenticate at `https://app.pax8.com` after Azure AD federation is enabled in Pax8 admin. Launching to bare `app.pax8.com` without `/login` is less explicit than SuperOps' technician login path pattern.

Consequences:
Technician launch URL: `https://app.pax8.com/login?login_hint=…`. Operator must configure Pax8 Enterprise SSO (Primary Partner Admin, DNS TXT, Finalize) and matching Pax8 app users with UPN aligned to Microsoft. Portal enforces `app.pax8.com` for federation (falls back if `.env` uses unsupported custom URL). Customer SSO remains `login_hint` + company deep link until Pax8 ships customer IdP SSO. Documented in [Pax8Integration.md](Pax8Integration.md) and [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md).

---

## ADR-018 (updated 2026-07-14)

Date: 2026-06-19 — updated 2026-07-14

Decision:
(1) Portal sync **auto-maintains** the customer security group via Graph (`GroupMember.ReadWrite.All`) when `entra_group_id` is set — technicians create an empty Assigned group only. (2) Each customer has separate Entra enterprise applications: `SuperOps - {Company}` for SCIM and `SuperOps Requester SSO - {Company}` for Client SSO.

Reason:
Auto-maintained membership removes bulk-add work. Separate apps keep SuperOps' independent SCIM and Client SSO configurations unambiguous and allow Entra Free to store and target distinct Application IDs for provisioning and sign-in assignment.

Consequences:
`ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true` remains the default. P1 assigns the maintained group to both apps. Entra Free stores `entra_superops_app_id` for SCIM and `entra_superops_sso_app_id` for Client SSO so Sync now can assign the correct users directly.

---

## ADR-019

Date: 2026-06-25

Decision:
On **Entra ID Free** (no group assignment to enterprise apps), portal sync assigns licensed users **and shared mailboxes** directly to the customer's SuperOps enterprise app via Graph (`AppRoleAssignment.ReadWrite.All`) when `clients.entra_superops_app_id` is set. Group membership is still auto-maintained for documentation and future P1 upgrade.

Reason:
Microsoft blocks assigning security groups to enterprise applications on Entra ID Free. SCIM only provisions users assigned to the app. Manual per-user assignment in Azure does not scale; portal sync already runs hourly with Graph access.

Consequences:
New client field `entra_superops_app_id` stores SuperOps **Application (client) ID** (resolved to enterprise app via `Application.Read.All`). Application permissions include `AppRoleAssignment.ReadWrite.All` + customer re-consent.

---

## ADR-020

Date: 2026-06-25 (revised 2026-06-25)

Decision:
SuperOps requester names use `Name (User Mailbox)` or `Name (Shared Mailbox)` **only in SuperOps**. Portal sync writes the **last name with suffix** to `extensionAttribute1` via `User.ReadWrite.All` and `EntraSyncDisplayName::formatSuperOpsFamilyName()`. Entra SCIM maps **name.familyName** **Direct** from that attribute (`name.formatted` stays Direct from `displayName`). **Do not patch Entra `displayName`.**

Reason:
Patching `displayName` polluted M365 Admin Center Active users. SCIM can carry the suffix via `extensionAttribute1` without changing the tenant directory.

Consequences:
`ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE` (default `1`). One-time SCIM **Direct** mapping: **name.familyName** ← extensionAttribute1 per customer SuperOps app. `portal:revert-entra-display-names` restores mistaken M365 suffixes. Sync output: `SuperOps last names updated N` and `SuperOps SCIM provision requested for N user(s)` (one Entra provision-on-demand call per user). Documented in [SuperOpsEntraSync.md](SuperOpsEntraSync.md#requester-display-names).

---

## ADR-021

Date: 2026-07-15

Decision:
Split customer-facing access into `client_requester`, `client_billing_admin`, and `client_admin` with explicit capability helpers and gates. Migrate legacy `client_user` → `client_requester`. Serve M365 directory and SuperOps dashboard metrics from per-client cache with queued background refresh jobs (`ShouldQueue` + database queue), not blocking Graph/API calls on page load.

Reason:
Client Admins need organisation-wide SuperOps visibility without exposing MSP credentials. M365 directory blocked HTTP requests on cache expiry (~300 serial Graph calls). Entra sync must not downgrade manually promoted roles.

Consequences:
Migration `2026_07_15_120000_migrate_client_user_to_client_requester`. New routes `/client-admin`, async M365 refresh. Documented in [ClientAdminDashboard.md](ClientAdminDashboard.md). ADR-007 role list superseded for client-facing roles.
