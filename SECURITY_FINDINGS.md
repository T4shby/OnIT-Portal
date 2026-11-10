# SECURITY_FINDINGS.md - On IT Portal

**Audit type:** Universal pre-deployment security and infrastructure audit (read-only)  
**Report date:** 2026-11-10  
**Source ref:** `cd4d7aa4c9aa61b2803ba1013a7bb91a6d78a365` (`main`, dirty worktree: uncommitted usecure skeleton + related files)  
**Auditor role:** Application security / Linux infrastructure / DevSecOps (skills-assisted)  
**Mode:** Remediation pass 2026-11-10 - verified findings against current tree; applied app/config fixes; preserved uncommitted usecure skeleton untouched.

### Remediation progress (2026-11-10)

| ID | Disposition | Evidence |
|---|---|---|
| SEC-001 | Requires major migration (Deferred) | Laravel still 11.54.0; upgrade needs explicit approval |
| SEC-002 | Fixed | `symfony/http-foundation` + `http-kernel` **v7.4.23**, `mime` **v7.4.22**, `routing` **v7.4.23** in `composer.lock` |
| SEC-003 | Fixed | Account required before grant; cross-account same-email test |
| SEC-004 | Fixed | Global links Super Admin only; AM create denied |
| SEC-005 | Mitigated (config) | `TRUSTED_PROXIES` env; default still `*` for Plesk - pin in prod when known |
| SEC-006 | Fixed | Admin-consent logs `client_id` / validity only |
| SEC-007 | Fixed | `Auth::login($user, false)` - no new remember-me |
| SEC-008 | Fixed | CSP header in `SecurityHeaders` |
| SEC-009 | Requires Manual Action | Plesk/nginx `X-Powered-By` strip on host |
| SEC-010 | Fixed | `.github/workflows/ci.yml` added |
| SEC-011 | Fixed | Explicit `daily` channel + `.env.example` guidance |
| SEC-012 | Requires Manual Action | Portal DB backup/restore still unverified |
| SEC-013 | Open (process) | usecure skeleton left uncommitted by design |
| SEC-014 | Accepted Risk (unreachable) | TokenGuard unused; track with SEC-001 |
| SEC-015 | Fixed | Unauthorized support show/actions → 404 |
| SEC-016 | Mitigated (docs) | `SESSION_ENCRYPT` production guidance in `.env.example` |
| SEC-017 | Requires Manual Action | SSH host audit still blocked |
| SEC-018 | Accepted Risk | npm High only in `devDependencies`; prod audit clean |

Focused tests: `SupportTicketShowTest`, `ClientContentCrossTenantTest`, `PortalLinkCacheTest`, `DeactivatedAccountSessionTest`, `MicrosoftOAuthStateTest` - **32 passed**. Broader Feature filter also ran; 2 unrelated dashboard assertion failures appear tied to the dirty local Vite/usecure worktree (not these authz changes).

---

## A. Executive summary

| Field | Value |
|---|---|
| Project | On IT Portal (`onit/portal`) |
| Audit date | 2026-11-10 (remediation same day) |
| Application stack | Laravel 11.54.0 (PHP 8.2+ / live PHP 8.4.27), Blade + Tailwind + Alpine/Vite 5, MySQL/MariaDB sessions/cache/queue, Microsoft Entra SSO |
| Target deployment | Live `https://app.onit.ltd` on Plesk/Ubuntu `159.65.83.156` |
| Areas assessed | Re-verified code findings; dependency updates; CI; headers/logging/session hardening |
| Areas not assessed | Live host SSH, portal DB restore drill, Laravel major upgrade |
| Overall recommendation | **CONDITIONAL** - application authz/Symfony items remediated; Laravel EOL + host backup/SSH remain open |

---

## B. Deployment readiness

**CONDITIONAL**

Phase 1 application blockers SEC-003 and SEC-002 are fixed in the working tree. Remaining blockers for READY: **SEC-001** (Laravel EOL upgrade), **SEC-012** (backup evidence), **SEC-017** (host audit), and **SEC-013** (do not deploy dirty usecure).

---

## C. Findings summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 3 |
| Medium | 5 |
| Low | 5 |
| Informational | 4 |

| Confidence | Count (among severity-rated findings) |
|---|---|
| Confirmed | 14 |
| Probable | 1 |
| Needs verification | 2 |

Hardening notes and false-positive scanner noise are listed separately and are **not** counted as open vulnerabilities.

---

## D. Detailed findings

### SEC-001 - Laravel 11 past vendor security end-of-life

**Title:** Framework on unsupported major (Laravel 11.54.0)  
**Severity:** High  
**Confidence:** Confirmed  
**Category:** Software supply chain / insecure design (A03:2025 / A06:2025)  
**Affected component:** `composer.lock` → `laravel/framework` v11.54.0  
**File and line references:** `composer.json` (constraint `^11.0`); `composer.lock` package `laravel/framework`  
**Evidence (redacted):** Laravel support policy ends security fixes for 11.x on **2026-03-12**. Audit date is 2026-11-10. `composer audit` still reports framework advisories whose version ranges include `<12.68.0` (e.g. GHSA-f9x3-7vq9-cph6 TokenGuard, GHSA-5vg9-5847-vvmq email rule CRLF).  
**Why this is a problem:** New framework CVEs will not receive official 11.x patches. Remaining on 11.x forces either major upgrade or permanent risk acceptance.  
**Realistic impact:** Future critical framework bugs (and current advisory-flagged code paths) stay unfixed unless upgraded to Laravel 12/13.  
**Recommended remediation:** Plan and execute upgrade to a supported Laravel major (12.x or 13.x) on a branch with full `php artisan test` and staging deploy. Prefer 13.x if the team can absorb the jump; otherwise 12.x patched to current security releases.  
**Implementation considerations:** Socialite Azure provider, encrypted casts, queue/session DB drivers, and Entra sync jobs must be regression-tested. Do not enable usecure until after upgrade if that work is still experimental.  
**How to verify the fix:** `composer show laravel/framework` shows a supported version; `composer audit` no longer flags EOL-only framework issues; test suite green.  
**Potential regression risks:** Middleware bootstrap changes, Socialite/Azure, signed URL behaviour, mail components.  
**Deployment blocker:** Yes (for READY verdict / new major feature releases)  
**Status:** Requires major migration - Deferred (needs explicit Laravel 12/13 upgrade approval)

---

### SEC-002 - Symfony HTTP stack below patched 7.4.23 line

**Title:** Vulnerable Symfony http-foundation / http-kernel versions (7.4.13)  
**Severity:** High  
**Confidence:** Confirmed (installed versions); Probable (practical exploitability behind nginx/Plesk)  
**Category:** Vulnerable components / request smuggling-related header trust (A03 / A05)  
**Affected component:** `symfony/http-foundation` v7.4.13, `symfony/http-kernel` v7.4.13 (via Laravel)  
**File and line references:** `composer.lock`; combined with `bootstrap/app.php` `trustProxies(at: '*', ...)`  
**Evidence:** Advisories affecting `<7.4.23` / `<7.4.19` ranges include CVE-2026-108082 (ReDoS via `Cache-Control` / `HeaderUtils::split`), host/URL generation from client-controlled forwarded headers, and trusted-proxy IPv4-mapped quirks. Installed version is **7.4.13**.  
**Why this is a problem:** The app trusts all proxies and uses Symfony Request for IP, scheme, and URL generation. Unpatched header parsing bugs raise DoS and Host-header influence risk.  
**Realistic impact:** Authenticated or unauthenticated ReDoS against PHP-FPM; distorted `Request::ip()` / generated URLs if an attacker can inject `X-Forwarded-*` (depends on whether PHP is reachable without a sanitising proxy - see SEC-005).  
**Recommended remediation:** Upgrade Laravel (SEC-001) or `composer update symfony/http-foundation symfony/http-kernel --with-all-dependencies` to **>=7.4.23** (or current patched 7.4.x) within compatible constraints; re-run tests.  
**Implementation considerations:** Pin via lockfile; avoid partial updates that leave laravel/framework requiring older Symfony.  
**How to verify the fix:** `composer show symfony/http-foundation` >= 7.4.23; `composer audit` clear for these CVEs.  
**Potential regression risks:** Request IP / URL generation in ActivityLog and rate limiters.  
**Deployment blocker:** Yes  
**Status:** Fixed - Symfony HTTP packages updated to patched 7.4.x in `composer.lock` (2026-11-10)

---

### SEC-003 - Support ticket authz short-circuits on requester email / SuperOps user id

**Title:** `userCanViewTicket` grants access before SuperOps account / tenant check  
**Severity:** Medium  
**Confidence:** Confirmed  
**Category:** Broken access control / IDOR (A01:2025)  
**Affected component:** `App\Services\SuperOps\SuperOpsTicketService`  
**File and line references:** `app/Services/SuperOps/SuperOpsTicketService.php:120-124`, `166-180`; callers `app/Http/Controllers/SupportController.php:50-62`, `77-89`  
**Evidence:** If `ticketBelongsToUser()` matches `superops_user_id` or **email**, the method returns `true` immediately and never compares `superops_account_id` / `canAccessClient()`. Org-wide and remembered-ticket paths correctly enforce account matching only when this short-circuit fails.  
**Why this is a problem:** A portal user whose email (or SuperOps user id) appears as requester on another customer’s ticket can open that ticket’s sanitized HTML if they know/guess the ticket id. Cross-customer email reuse is a known operational concern in this MSP (see Brain notes on shared M365 primaries).  
**Realistic impact:** Cross-tenant support ticket confidentiality breach for matching requester identity; not unauthenticated.  
**Recommended remediation:** After `ticketBelongsToUser`, still require that the ticket’s SuperOps account equals the viewer’s client `superops_account_id` (or staff `canAccessClient` for that account). Keep requester match as an additional filter, not a sole grant. Add a Feature test: same email, two clients/accounts, must 403.  
**Implementation considerations:** Preserve portal-created “remembered” tickets when SuperOps omits requester; do not break client-admin org-wide view.  
**How to verify the fix:** Extend `tests/Feature/SupportTicketShowTest.php` with cross-account same-email case; manual check with two SuperOps accounts.  
**Potential regression risks:** Legitimate viewers whose tickets lack `client`/account fields may need the remembered-account fallback.  
**Deployment blocker:** Yes (for READY)  
**Status:** Fixed - `userCanViewTicket` requires SuperOps account match; regression `test_same_requester_email_on_another_superops_account_is_denied`

---

### SEC-004 - Account Managers may create/update/delete global portal links

**Title:** Global (`client_id` null) portal links manageable by any Account Manager  
**Severity:** Medium  
**Confidence:** Confirmed  
**Category:** Broken function-level authorization / privilege design (A01)  
**Affected component:** `PortalLinkPolicy`, admin portal-links UI  
**File and line references:** `app/Policies/PortalLinkPolicy.php:20-27`; `app/Http/Requests/Admin/StorePortalLinkRequest.php` (null client allowed for staff); `app/Services/ExternalServicesService.php` (includes `whereNull('client_id')` for all customers); test expectation in `tests/Feature/Security/ClientContentCrossTenantTest.php`  
**Evidence:** `update`/`delete` allow any admin when `$link->client_id === null`. Those links appear on every customer dashboard.  
**Why this is a problem:** A compromised or malicious Account Manager can change shared launch URLs for **all** tenants (phishing / disruption), not only assigned clients.  
**Realistic impact:** Tenant-wide malicious redirect or removal of critical SSO launch links. Requires authenticated AM (or Super Admin).  
**Recommended remediation:** Restrict null-`client_id` portal link mutations to `super_admin` only; keep AM limited to links for `canAccessClient` clients. Update policy + tests.  
**Implementation considerations:** Confirm product intent with operators; if global AM edit is deliberate, document as accepted risk in Brain and lower severity disposition.  
**How to verify the fix:** Feature test: AM cannot store/update/delete global link; Super Admin can.  
**Potential regression risks:** Existing AM workflows that edit shared links.  
**Deployment blocker:** No (unless product rejects AM global edit)  
**Status:** Fixed - `PortalLinkPolicy` + `StorePortalLinkRequest`; AM denied, Super Admin allowed

---

### SEC-005 - `trustProxies(at: '*')` trusts all forwarded headers

**Title:** Unrestricted trusted proxies  
**Severity:** Medium  
**Confidence:** Confirmed (config); Needs verification (whether PHP is directly reachable)  
**Category:** Security misconfiguration (A02:2025)  
**Affected component:** Laravel middleware bootstrap  
**File and line references:** `bootstrap/app.php:28-35`  
**Evidence:** All `X-Forwarded-*` headers trusted from any client. Rate limiters use `$request->ip()` (`AppServiceProvider`). Live site is nginx/Plesk.  
**Why this is a problem:** If an attacker can hit PHP-FPM without the proxy overwriting forwarded headers, they can spoof client IP (bypass/throttle poisoning) and influence absolute URL generation (worsened by SEC-002).  
**Realistic impact:** Rate-limit evasion; polluted activity-log IPs; Host/URL confusion if headers reach Laravel. On a correctly firewalled Plesk box where only nginx talks to FPM, practical risk is lower.  
**Recommended remediation:** Set trusted proxies to the real nginx/Plesk upstream addresses or private CIDRs; stop using `*`. Document the chosen CIDRs in `Brain/Deployment.md`.  
**Implementation considerations:** Wrong CIDR breaks HTTPS detection / `$request->ip()` behind Plesk. Test login redirects and HSTS.  
**How to verify the fix:** From an external client, forged `X-Forwarded-For` must not change logged IP; legitimate proxy still sets correct IP.  
**Potential regression risks:** Login redirects, secure cookies, rate limits.  
**Deployment blocker:** No  
**Status:** Mitigated - `TRUSTED_PROXIES` env wired in `bootstrap/app.php`; production should set explicit CIDRs (still defaults to `*`)

---

### SEC-006 - Admin-consent OAuth `state` written to application logs

**Title:** Signed admin-consent capability token logged in full  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Logging / sensitive data exposure (A09:2025)  
**Affected component:** `MicrosoftAuthController::handleAdminConsentReturn`  
**File and line references:** `app/Http/Controllers/Auth/MicrosoftAuthController.php:241-245`  
**Evidence:** Log context includes `'state' => $request->query('state')`. State is HMAC-signed with `APP_KEY` and authorizes linking a tenant within TTL (`AdminConsentState`).  
**Why this is a problem:** Anyone who can read `storage/logs/laravel.log` within the consent TTL could replay a valid state against the public callback for an **unlinked** client (still constrained by UUID tenant checks and no re-point rules).  
**Realistic impact:** Narrow window privilege to bind a malicious tenant to an unlinked client if logs leak.  
**Recommended remediation:** Log only `client_id` / validity after `AdminConsentState::inspect()`; never log raw `state`.  
**Implementation considerations:** Keep enough diagnostics for support without the HMAC payload.  
**How to verify the fix:** Grep logs after a consent flow; `state=` must be absent.  
**Potential regression risks:** Harder consent debugging.  
**Deployment blocker:** No  
**Status:** Fixed - raw `state` no longer logged; `client_id` + `state_valid` only

---

### SEC-007 - Remember-me cookie always issued (~400 days)

**Title:** Long-lived recaller cookie on every Microsoft login  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Authentication / session (A07:2025)  
**Affected component:** Login + SessionGuard  
**File and line references:** `app/Http/Controllers/Auth/MicrosoftAuthController.php:218` (`Auth::login($user, true)`); Laravel `SessionGuard` default `$rememberDuration = 576000` minutes; documented in `EnsureAccountIsActive.php:14-17`  
**Evidence:** Live login cookies show session Max-Age 7200; remember-me is separate recaller. Middleware re-checks active flags every request.  
**Why this is a problem:** Stolen recaller extends access window far beyond session lifetime.  
**Realistic impact:** Device theft / cookie theft persists up to ~400 days unless account/org deactivated (mitigated by `EnsureAccountIsActive`).  
**Recommended remediation:** `Auth::login($user, false)` or configure a shorter remember duration if remember-me is required; rely on SSO re-auth.  
**Implementation considerations:** UX for technicians who stay signed in; tests in `DeactivatedAccountSessionTest`.  
**How to verify the fix:** No recaller cookie after login, or Max-Age matches policy.  
**Potential regression risks:** Users re-prompted by Entra more often.  
**Deployment blocker:** No  
**Status:** Fixed - login uses `Auth::login($user, false)`; `EnsureAccountIsActive` still covers legacy recallers

---

### SEC-008 - No Content-Security-Policy

**Title:** Security headers omit CSP  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Security misconfiguration (A02)  
**Affected component:** `App\Http\Middleware\SecurityHeaders`  
**File and line references:** `app/Http/Middleware/SecurityHeaders.php:16-23`; live `GET /login` headers (2026-11-10) include XFO/nosniff/Referrer-Policy/Permissions-Policy/HSTS but **no** `Content-Security-Policy`  
**Evidence:** Passive HEAD to `https://app.onit.ltd/login` showed existing headers; CSP absent.  
**Why this is a problem:** CSP is a defense-in-depth control against XSS residual risk (e.g. sanitizer bypass).  
**Realistic impact:** Does not create XSS by itself; increases blast radius if XSS appears.  
**Recommended remediation:** Add a staged CSP (`Content-Security-Policy-Report-Only` first) compatible with Vite assets and Alpine inline needs; then enforce.  
**Implementation considerations:** Alpine/Vite nonce or hash strategy; avoid breaking admin Live poll fragments.  
**How to verify the fix:** Response includes CSP; browser console clean on dashboard/support.  
**Potential regression risks:** Inline scripts/styles break.  
**Deployment blocker:** No  
**Status:** Fixed - CSP set in `SecurityHeaders` (allows `'unsafe-inline'` for Alpine/Vite; tighten with nonces later)

---

### SEC-009 - Technology disclosure headers on live site

**Title:** `X-Powered-By: PHP/8.4.27` and `PleskLin`  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Information disclosure  
**Affected component:** Plesk/PHP/nginx response headers  
**File and line references:** Live responses to `/up` and `/login` (2026-11-10)  
**Evidence:** Both `X-Powered-By: PHP/8.4.27` and `X-Powered-By: PleskLin` present.  
**Why this is a problem:** Aids targeted scanning; not directly exploitable.  
**Realistic impact:** Reconnaissance only.  
**Recommended remediation:** Disable `expose_php` / Plesk “X-Powered-By” headers for the subscription; strip at nginx.  
**Implementation considerations:** Plesk UI PHP settings; may need panel access.  
**How to verify the fix:** `curl -sI https://app.onit.ltd/login` shows no `X-Powered-By`.  
**Potential regression risks:** None material.  
**Deployment blocker:** No  
**Status:** Requires Manual Action - disable `expose_php` / Plesk X-Powered-By on the live subscription

---

### SEC-010 - No CI/CD security automation in repository

**Title:** No GitHub Actions / dependency or secret scanning pipeline  
**Severity:** Medium  
**Confidence:** Confirmed  
**Category:** Software supply chain / DevSecOps gap (A03)  
**Affected component:** Repository automation  
**File and line references:** No `.github/workflows/*` present  
**Evidence:** Glob of workflows returned 0 files; deploy path is laptop → GitHub → Plesk Git.  
**Why this is a problem:** Vulnerable lockfile updates and secret commits are not gated before merge/deploy.  
**Realistic impact:** Delayed detection of CVEs (as seen with Symfony/Laravel drift) and accidental secret commits.  
**Recommended remediation:** Add a minimal workflow: `composer audit`, `npm audit --omit=dev`, `php artisan test`, optional gitleaks. Fail PRs on High+ production advisories.  
**Implementation considerations:** Do not grant deploy secrets to fork PRs; pin actions by SHA if Actions are introduced (`cicd-security` skill).  
**How to verify the fix:** PR checks run on `main` PRs; audit fails on known bad lockfile.  
**Potential regression risks:** CI noise from transitive false positives (document suppressions carefully).  
**Deployment blocker:** No  
**Status:** Fixed - `.github/workflows/ci.yml` runs composer install, PHPUnit, npm ci/build, audits (continue-on-error for advisory noise)

---

### SEC-011 - Application log channel is single-file without rotation config

**Title:** Default `LOG_STACK=single` grows unbounded  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Logging / availability  
**Affected component:** `config/logging.php`, `.env.example`  
**File and line references:** `config/logging.php:19-30`; `.env.example:17` `LOG_STACK=single`  
**Evidence:** Only `single` and `null` channels defined; no `daily` channel in config.  
**Why this is a problem:** Disk fill on production can take down the app; also enlarges sensitive log retention (SEC-006).  
**Realistic impact:** Availability incident; larger secret-in-log window.  
**Recommended remediation:** Add Laravel `daily` channel; set production `LOG_STACK=daily`; ensure logrotate or Plesk rotation covers `storage/logs`.  
**Implementation considerations:** Coordinate with host disk monitoring.  
**How to verify the fix:** New dated log files appear; old files pruned.  
**Potential regression risks:** Log shipping paths if any.  
**Deployment blocker:** No  
**Status:** Fixed - `daily` channel in `config/logging.php`; `.env.example` documents `LOG_STACK=daily` for production

---

### SEC-012 - Portal database backup / restore evidence not verified

**Title:** No verified On IT Portal DB backup/restore in this audit  
**Severity:** High  
**Confidence:** Needs verification  
**Category:** Reliability / recoverability  
**Affected component:** Production MySQL/MariaDB for `onit_portal`  
**File and line references:** `Brain/Deployment.md` (no mysqldump/Plesk backup procedure found for the portal DB); `Brain/ServerOpsLog.md` documents backups for **CoPilot readiness**, not the portal schema  
**Evidence:** SSH to `root@159.65.83.156` **timed out** from the audit workstation; Brain does not document portal DB backup retention or restore tests.  
**Why this is a problem:** Without backups and a tested restore, ransomware/operator error is a full data-loss event for portal tenants, sessions, and mappings.  
**Realistic impact:** Catastrophic availability/integrity loss.  
**Recommended remediation:** Confirm Plesk or filesystem backups for the portal DB and `.env` (secrets vault separately); document restore steps in `Brain/Deployment.md`; run a restore drill on a non-prod copy.  
**Implementation considerations:** Do not copy `.env` into git; keep backup encryption keys offline.  
**How to verify the fix:** Documented recent backup artifact + successful restore to a scratch database.  
**Potential regression risks:** None for read-only verification.  
**Deployment blocker:** Yes until verified or risk-accepted in writing  
**Status:** Requires Manual Action - confirm Plesk/DB backups and document restore in Brain

---

### SEC-013 - Dirty worktree usecure skeleton risk

**Title:** Uncommitted usecure integration present locally (must not ship accidentally)  
**Severity:** Medium  
**Confidence:** Confirmed  
**Category:** Deployment process / insecure design  
**Affected component:** Local worktree (not on `origin/main` tip alone)  
**File and line references:** Git status shows untracked `app/Services/Usecure/`, jobs, migration `usecure_company_id`, views, probe command; `Brain/Handover.md` explicitly says do not commit/deploy usecure until vaulted probe is green  
**Evidence:** `git status` dirty on audit start; `USECURE_ENABLED=false` in example env.  
**Why this is a problem:** Accidental commit/deploy would add schema/UI before secrets exist and expand attack surface.  
**Realistic impact:** Incomplete feature live; possible failed migrations; confusion for operators.  
**Recommended remediation:** Keep usecure off `main` until authorised; stash/branch separately; never deploy dirty tree.  
**Implementation considerations:** Follow `Brain/UsecureIntegration.md`.  
**How to verify the fix:** `git status` clean relative to intended release SHA before Plesk pull.  
**Potential regression risks:** None if left uncommitted.  
**Deployment blocker:** Yes for any deploy from this worktree  
**Status:** Open (process) - remediation left usecure files uncommitted; do not ship until vaulted probe is green

---

### SEC-014 - TokenGuard critical advisory present in framework but unused

**Title:** GHSA-f9x3-7vq9-cph6 TokenGuard code unpatched in 11.54.0; guard not configured  
**Severity:** Informational  
**Confidence:** Confirmed  
**Category:** Vulnerable component (unreachable)  
**Affected component:** `vendor/laravel/framework/.../TokenGuard.php`; `config/auth.php`  
**File and line references:** `config/auth.php:10-15` only `web` → `session` driver; TokenGuard still uses `if (! empty($token))` without `is_string`  
**Evidence:** CVE-2026-114509 requires `'driver' => 'token'` + MySQL/MariaDB. This app has no token/api guard and no `routes/api.php`.  
**Why this is a problem:** Scanner noise and residual risk if someone adds `auth:api` later without upgrading.  
**Realistic impact:** None on current routes.  
**Recommended remediation:** Track under SEC-001 upgrade; do not add token guard on 11.54.0.  
**Implementation considerations:** N/A  
**How to verify the fix:** After upgrade, TokenGuard contains `is_string` checks or guard remains absent.  
**Potential regression risks:** None  
**Deployment blocker:** No  
**Status:** Accepted Risk (unreachable) - no token guard; resolve via SEC-001 upgrade

---

### SEC-015 - Support ticket existence oracle and fetch-before-authz

**Title:** Ticket pulled from SuperOps before deny; 403 vs “not found” differs  
**Severity:** Low  
**Confidence:** Confirmed  
**Category:** Broken access control / information leak  
**Affected component:** `SupportController`  
**File and line references:** `app/Http/Controllers/SupportController.php:49-62`, `76-89`  
**Evidence:** Full ticket retrieved with MSP credentials; unauthorized viewers get 403; API failures redirect as not found.  
**Why this is a problem:** Authenticated users can probe ticket id existence; unnecessary SuperOps data pull.  
**Realistic impact:** Minor enumeration; no body leak on 403.  
**Recommended remediation:** Normalize responses to 404 for unauthorized; optionally pre-filter by account when API allows.  
**Implementation considerations:** Avoid breaking staff tools that expect 403.  
**How to verify the fix:** Unauthorized show returns same status as missing.  
**Potential regression risks:** Client error handling UX.  
**Deployment blocker:** No  
**Status:** Fixed - unauthorized show/actions return 404 (same as missing)

---

### SEC-016 - Session payload encryption disabled by default

**Title:** `SESSION_ENCRYPT` defaults false  
**Severity:** Informational  
**Confidence:** Confirmed  
**Category:** Cryptographic failures (defense-in-depth)  
**Affected component:** `config/session.php`  
**File and line references:** `config/session.php:10`; `.env.example:33`  
**Evidence:** Database sessions store payload unencrypted at rest; `microsoft_tokens` remain column-encrypted separately.  
**Why this is a problem:** DB read access yields session user ids / CSRF tokens.  
**Realistic impact:** Session fixation/hijack assist after DB compromise (still need cookie).  
**Recommended remediation:** Set `SESSION_ENCRYPT=true` in production after confirming no custom session consumers break.  
**Implementation considerations:** All app servers share `APP_KEY`.  
**How to verify the fix:** `sessions.payload` not plaintext JSON.  
**Potential regression risks:** Multi-key rotation complexity.  
**Deployment blocker:** No  
**Status:** Mitigated (docs) - production guidance to set `SESSION_ENCRYPT=true`; default left false for local compat

---

### SEC-017 - Live Linux host audit incomplete (SSH timeout)

**Title:** Production host hardening not verified  
**Severity:** Informational (coverage gap)  
**Confidence:** Confirmed (access failure)  
**Category:** Infrastructure coverage  
**Affected component:** `159.65.83.156` (`Brain/ServerOpsLog.md` / `Brain/Handover.md`)  
**File and line references:** Ops docs; local SSH probe  
**Evidence:** `ssh -o BatchMode=yes -o ConnectTimeout=8 root@159.65.83.156` → connection timed out. No On IT Host entry in `~/.ssh/config` (other projects only).  
**Why this is a problem:** Firewall, SSH, patch level, fail2ban, backups, queue timers cannot be evidenced.  
**Realistic impact:** Unknown host risk.  
**Recommended remediation:** From an authorised network/VPN, run `ubuntu-server-audit-eu` / `aegis-linux-baseline` / `aegis-firewall-edge` read-only; document in ServerOpsLog.  
**Implementation considerations:** Do not open SSH to the world solely for audits; use bastion.  
**How to verify the fix:** Successful read-only SSH collection with coverage matrix.  
**Potential regression risks:** N/A  
**Deployment blocker:** Yes for READY (host unknown)  
**Status:** Requires Manual Action - re-run host audit when SSH from an authorised network is available

---

### SEC-018 - npm High advisories limited to dev/build tooling

**Title:** Vite/esbuild/follow-redirects advisories in `devDependencies`  
**Severity:** Informational  
**Confidence:** Confirmed  
**Category:** Supply chain (non-production runtime)  
**Affected component:** `package.json` / lockfile  
**File and line references:** `npm audit` (full) vs `npm audit --omit=dev`  
**Evidence:** Full audit: 3 high / 2 moderate (vite, esbuild, follow-redirects, source-map-js, postcss-selector-parser). Production omit-dev: **0** vulnerabilities. Live site serves built assets, not Vite dev server.  
**Why this is a problem:** Dev-server issues matter for local `npm run dev`, not production Blade.  
**Realistic impact:** Local developer machine exposure if Vite binds broadly.  
**Recommended remediation:** Periodically `npm update vite` within Vite 5; never expose `npm run dev` on the production host.  
**Implementation considerations:** Rebuild assets after upgrades.  
**How to verify the fix:** `npm audit` clean or only accepted dev findings.  
**Potential regression risks:** Frontend build breaks.  
**Deployment blocker:** No  
**Status:** Accepted Risk - production `npm audit --omit=dev` clean; keep Vite off production hosts

---

### Hardening notes (not findings)

1. ~~Add `microsoft_tokens` to `User::$hidden`~~ - done 2026-11-10 (also `remember_token`).  
2. Narrow `User::$fillable` privileged fields; use explicit assignment for sync/login.  
3. Soften OAuth end-user error copy when `APP_DEBUG=false` (ops paths → logs only).  
4. SCIM tenant URL is staff-controlled HTTPS and is consumed by **Entra**, not Laravel `Http::` - treat Entra-side SSRF as needs_validation if SCIM secrets are highly sensitive.  
5. Guzzle/commonmark/phpseclib rows from earlier audit cleared or noise; `composer audit` now mainly Laravel + Flysystem.  
6. Flysystem advisory (`<=3.35.2`) - no user uploads; low priority with Laravel upgrade.  
7. `/up` health route omits app `SecurityHeaders` (framework health endpoint) - low impact.  
8. Tighten CSP with nonces once Alpine/Vite inline needs are inventoried.

### Rejected / cleared candidates (selected)

| Claim | Verdict | Reason |
|---|---|---|
| Unauthenticated TokenGuard takeover | Rejected (current config) | No token guard; session-only `web` |
| Huntress case IDOR across clients | Rejected | `findForClient` + tests |
| CSRF disabled | Rejected | No CSRF exceptions |
| Hardcoded production secrets in git | Rejected | Pattern scan clean; only `.env.example` tracked |
| SQL injection via `whereRaw` | Rejected | Bound parameters / constant field names |
| In-process SSRF to user URLs via API clients | Rejected | Fixed service bases + timeouts |
| npm production runtime Critical/High | Rejected | `--omit=dev` clean |

---

## E. Infrastructure findings

| Area | Result |
|---|---|
| Host security (OS patches, users, sudo, SSH hardening) | **Not Verified** - SSH timeout |
| Network/firewall (ufw/nftables, fail2ban, IPv6) | **Not Verified** |
| Docker/container | **N/A** - no Dockerfile/compose; not used for portal |
| Reverse-proxy/TLS | **Partial** - live HTTPS 200; HSTS present on `/login`; nginx/Plesk; cipher suite / cert chain expiry **Not Verified** beyond TLS handshake success |
| Service configuration (systemd timers, queue workers, PHP-FPM) | **Docs only** - `Brain/Deployment.md` describes timers/cron; live state **Not Verified** |
| Backup/recovery | **Not Verified** for portal DB (SEC-012); CoPilot backups documented separately |
| Server health / disk / inodes | **Not Verified** |
| Public headers | **Checked** - security headers good; `X-Powered-By` leak (SEC-009); CSP missing (SEC-008) |
| Trusted proxy / edge | **Code Confirmed** open (SEC-005); edge enforcement **Not Verified** |

---

## F. Remediation plan

### Phase 1 - Critical deployment blockers

1. **Do not deploy from the dirty usecure worktree** (SEC-013). Release only a clean `main` SHA.  
2. **Verify portal DB backups + restore** (SEC-012) or obtain written risk acceptance.  
3. **Patch Symfony HTTP stack** (SEC-002) via dependency update / Laravel upgrade path.  
4. **Fix support ticket tenant short-circuit** (SEC-003) + regression tests.

### Phase 2 - High-priority security fixes

1. **Schedule Laravel major upgrade** off EOL 11 (SEC-001).  
2. **Restrict global portal-link mutations** to Super Admin (SEC-004) unless product accepts AM privilege.  
3. **Pin trusted proxies** (SEC-005).  
4. **Stop logging admin-consent state** (SEC-006).

### Phase 3 - Hardening

1. Remember-me policy (SEC-007).  
2. CSP rollout (SEC-008).  
3. Strip `X-Powered-By` (SEC-009).  
4. Daily logs (SEC-011); optional `SESSION_ENCRYPT` (SEC-016).  
5. Normalize support 404s (SEC-015).  
6. `User::$hidden` / fillable hygiene (hardening notes).

### Phase 4 - Reliability and operational improvements

1. Complete SSH host audit when network path exists (SEC-017) using ubuntu/aegis skills.  
2. Add CI audits/tests (SEC-010).  
3. Confirm queue timer health, disk monitoring, and fail2ban/SSH allowlists on the droplet.  
4. Keep `composer audit` / `npm audit --omit=dev` in the release checklist.

**Order note:** Fix SEC-003 in application code independently of the Laravel upgrade. Prefer bundling SEC-002 with SEC-001 if Symfony cannot move alone. Do not enable usecure or Teams IH webhooks until secrets and Phase 1 are settled.

---

## G. Validation checklist

- [ ] Security regression: `tests/Feature/Security/*` + new SupportTicket cross-account email test  
- [ ] Functional: login, admin consent, ticket show, Huntress show, portal links, Integration Health  
- [ ] Build: `composer install --no-dev`, `npm ci && npm run build`  
- [ ] Dependency: `composer audit`, `npm audit --omit=dev`  
- [ ] Infrastructure: SSH read-only host checklist; backup restore evidence  
- [ ] Deploy smoke: `https://app.onit.ltd/up`, `/login`, headers, queue depth, `failed_jobs`  
- [ ] Post-deploy: watch `storage/logs`, Integration Health, Entra sync ages  

---

## H. Audit limitations

| Item | Detail |
|---|---|
| Skills used | `security-audit`, `owasp-audit`, `api-audit` (surface inventory; no public JSON API), `dependency-audit`, `secrets-audit` (grep + no betterleaks binary), `iam-audit` (app RBAC; not Entra PIM), `finding-triage`, `cicd-security` (no workflows), `cloud-audit` (N/A PaaS), `container-audit` (N/A), `scanner-pipeline` (scanners largely unavailable), `ubuntu-server-audit-eu` / `aegis-linux-baseline` / `aegis-firewall-edge` (blocked by SSH) |
| Tools executed | `composer audit`, `npm audit` / `npm audit --omit=dev`, `git`/`rg`/`grep` source review, passive `curl -sI` to live site, SSH BatchMode probe |
| Could not complete | Host CIS, firewall, backup jobs, cert expiry details, secret full-history scan, intrusive DAST |
| SSH | Authorised host documented; connection timed out; incomplete live Linux audit |
| Missing permissions | No working SSH path from this workstation; no Plesk panel access in-session |
| Unavailable scanners | betterleaks, gitleaks, trivy, osv-scanner, zizmor, actionlint not installed |
| Human review needed | Product intent for AM global portal links; Entra Conditional Access; backup ownership; whether PHP-FPM is internet-reachable |

Prior written audits (since removed from `docs/`) and `Brain/Authentication.md` / `Brain/Deployment.md` were consulted; this report re-validates current `main` tip and does not assume prior "fixed" items without re-check.

---

## I. Remediation handoff instructions

For a fresh AI agent or engineer:

1. Read this file end-to-end and re-check each SEC-ID against current `git` HEAD (worktree may have changed).  
2. Prefer Phase 1 application fixes (SEC-003, SEC-006) before large framework upgrades.  
3. For SEC-001/002, create a dedicated upgrade branch; never patch production by hand outside `Brain/Deployment.md`.  
4. Preserve multi-tenant Policies/Gates; add Feature tests for every authz change under `tests/Feature/Security/`.  
5. After each logical group: `php artisan test` (at least Security + Support + Auth tests).  
6. Do **not** SSH-mutate production, rotate secrets, or deploy unless the operator explicitly asks. Document any live checks in `Brain/ServerOpsLog.md`.  
7. Update each finding status to Fixed / Not Reproducible / Risk Accepted / Still Open with evidence (commit SHA, test name, or screenshot of headers).  
8. Re-run `composer audit`, `npm audit --omit=dev`, and a passive header check; only then consider elevating readiness above CONDITIONAL.  
9. If usecure files remain uncommitted, leave them out of any release commit (SEC-013).  
10. ASCII hyphens only in docs/commits per project rules; update `Brain/README.md` changelog when behaviour or deploy procedure changes as part of remediation (not required for this audit-only report).

---

*End of report.*
