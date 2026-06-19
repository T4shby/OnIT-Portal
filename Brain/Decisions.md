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
Role checks required on every route and policy. Account managers use client_user pivot for multi-client access. Role enum stored as string in database.

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

## ADR-013 (updated 2026-06-19)

Date: 2026-06-12 — updated 2026-06-19

Decision:
Configure **SuperOps Requester SSO** via a dedicated Entra SAML enterprise app for **client users only**. Technicians launch to `/#/technician/login` on `SUPEROPS_PORTAL_URL` using the **same M365 identity** from portal OAuth — SuperOps maps MSP staff to technician; no second SAML app needed (confirmed: requester URL + Tom's account still lands as technician).

Reason:
Portal client users map to SuperOps **requesters** and need Requester Global SSO (SAML). On IT staff are already M365 users in SuperOps as **technicians** — only the launch endpoint differs.

Consequences:
Requester SAML: Entra app #2 + SuperOps Global SSO for clients. Technician launch: portal role routing + `SUPEROPS_PORTAL_URL` — same auth, different SuperOps SPA route.

Consequences:
Two Entra apps in production (portal OAuth + SuperOps requester SAML). Entra Login URL is configured in SuperOps Global SSO only; portal redirects to `portal.onit.ltd` for SP-initiated SAML.

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
