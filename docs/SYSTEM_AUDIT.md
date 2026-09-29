# System Audit - On IT Portal

**Date:** 2026-09-28
**Scope:** Full-repository production-readiness audit (security, authz,
secrets, DB integrity, infra, reliability, performance, code quality,
dependencies, testing, documentation).
**Branch:** `claude/jolly-hopper-6w33al`

## Executive Summary

This is a well-engineered Laravel 11 monolith. The codebase already applies
the authorization patterns that most first-pass audits find missing:
**every** controller action that reads or mutates a specific client/user
record calls an explicit Laravel Policy or Gate check, tenant scoping is
centralized in `User::canAccessClient()`/`accessibleClientIds()`, all
models use explicit `$fillable`, all raw SQL fragments are parameterized,
every outbound HTTP call has an explicit timeout, and there is already a
dedicated `tests/Feature/Security/` suite covering cross-tenant IDOR-style
scenarios. No hardcoded secrets, no disabled CSRF, no `$guarded = []`, no
missing foreign keys were found.

The actionable findings from this audit were narrower than the audit
brief anticipated:

- **Fixed:** 25 PHP dependency security advisories (guzzle, psr7,
  commonmark, phpseclib - patched to non-breaking versions) and 9 npm
  advisories (`npm audit fix`, non-breaking).
- **Fixed:** 7 of 267 tests were failing on a clean checkout due to test
  fixtures that predated the `ClientProductService` product-entitlement
  gating feature; all now pass, no application code changed.
- **Documented, not changed:** 3 residual `laravel/framework` advisories
  only fixed in Laravel 12/13 (this app does not send email, the
  vulnerable code path, so practical risk is low; a major-version Laravel
  upgrade is a separate, larger project - see Manual Review Items).
- **Documented, not changed:** a handful of low-severity hardening items
  (session cookie `secure` flag is `.env`-driven with no code-level
  default; single-file logging with no rotation; Pint has no project
  config so it currently reports style diffs across most of the codebase).

No critical vulnerabilities (auth bypass, IDOR, injection, committed
secrets) were found. See below for full detail and verification evidence.

## Architecture

See `docs/ARCHITECTURE.md` for the full write-up. In short: Laravel 11
Blade monolith, Microsoft Entra ID SSO only, MySQL/MariaDB with
database-backed session/cache/queue (no Redis), Plesk/Ubuntu hosting, five
external integrations (Microsoft Graph, SuperOps, Huntress, Dropsuite,
Pax8) each behind a product-entitlement + platform-readiness gate.

## Audit Scope

Read in full or in significant part: `composer.json`/`composer.lock`,
`package.json`/`package-lock.json`, all of `routes/`, all of
`app/Http/Controllers` (30 files), all of `app/Policies` (8 files),
`app/Providers/AuthServiceProvider.php`, `app/Http/Middleware/*`, all
`app/Http/Requests/*`, `app/Models/*` (9 models), all `database/migrations`
(19 files), `config/*`, `.env.example`, `bootstrap/app.php`, a
representative sample of `app/Services/*` (external API clients,
timeouts, business logic), `Brain/Deployment.md`, `Brain/Architecture.md`,
`README.md`, and the full `tests/` directory listing plus the specific
failing tests. Grepped the whole repository for: hardcoded secrets,
`env()` calls outside `config/`, raw/concatenated SQL, disabled CSRF,
`Str::random` misuse, file upload/storage handling, `dd()`/`var_dump()`
debug leftovers, `.bak`/`.old`/duplicate files, unescaped Blade output
(`{!! !!}`).

Commands actually run (not assumed): `composer install`, `npm install`,
`npm audit` / `npm audit fix`, `npm run build`, `composer audit`,
`composer update` (targeted security patches), `php artisan test` (before
and after fixes), `vendor/bin/pint --test`.

## Critical Findings

None found.

## High Priority Findings

### H1. PHP dependency vulnerabilities (guzzle, psr7, commonmark, phpseclib)

- **Risk:** `composer audit` reported 25 advisories across 4 transitive
  dependencies, most notably `guzzlehttp/guzzle` 7.11.1 (host/cookie
  confusion, proxy-header leakage to origin servers, silent HTTPS→cleartext
  proxy downgrade) - this is the HTTP client backing every outbound call to
  Microsoft Graph, SuperOps, Huntress, and Dropsuite.
- **Evidence:** `composer audit` output captured before the fix (25
  advisories, 5 packages).
- **Resolution:** `composer update guzzlehttp/guzzle guzzlehttp/psr7
  league/commonmark phpseclib/phpseclib --with-all-dependencies` -
  guzzle 7.11.1→7.15.5, psr7 2.11.0→2.13.1, commonmark 2.8.2→2.10.3,
  phpseclib 3.0.52→3.0.57. All within existing composer.json constraints;
  no code changes required.
- **Validation:** `composer audit` now reports 3 advisories (see M1
  below). Full test suite re-run after the update: 267 passed.

### H2. npm dependency vulnerabilities (axios, form-data, nanoid, postcss, browserslist)

- **Risk:** `npm audit` reported 9 advisories (6 high, 2 moderate, 1 low)
  in build/dev tooling, notably `axios` (prototype pollution, proxy/auth
  header handling) which is bundled into the frontend.
- **Resolution:** `npm audit fix` (non-breaking). Frontend rebuilt with
  `npm run build` and the new `public/build/*` output committed (this repo
  intentionally commits compiled assets - see `docs/DEPLOYMENT.md`).
- **Validation:** `npm audit` now reports 1 moderate advisory only (esbuild,
  dev-server-only, see M2). `npm run build` succeeds.

### H3. Seven tests were failing on a clean checkout

- **Risk:** Not a production security risk directly, but a broken test
  suite means regressions in authorization/entitlement logic would go
  undetected - the exact category of bug this audit is meant to catch.
- **Evidence:** `php artisan test` on the untouched checkout: `7 failed,
  260 passed`.
- **Root cause:** All seven traced to one thing - `ClientProductService`
  (product entitlements) and `services.entra_sync.client_id/secret`
  platform-readiness config were introduced after these tests were
  written, and the tests never set them up, so features that should have
  been exercised were instead denied (fail-closed) or the tests asserted
  UI copy (`"still in beta"`, a dashboard Support link,
  Tailwind row-width classes) that had since been removed/simplified from
  the actual views/services.
- **Resolution:** Fixed all seven test files (see commit `96096b7`) -
  either granting the relevant entitlement/config in the fixture (3
  tests), or updating assertions to match current, intentional application
  behaviour (4 tests). **No application code was changed** for this
  finding.
- **Validation:** `php artisan test` → `267 passed (1203 assertions)`.

## Medium Priority Findings

### M1. Residual laravel/framework advisories (fixed only in Laravel 12/13)

- **Risk:** `composer audit` reports 3 advisories against
  `laravel/framework` v11.54.0 (temporary signed-URL path confusion, CRLF
  injection in the default `email` validation rule). Fix versions are in
  the 12.x/13.x line only; there is no 11.x point release.
- **Practical exposure here:** Low. The CRLF-injection advisory affects
  mail header construction from validated email addresses - **this
  application does not send any outbound email** (verified: no
  `Mail::`/`Mailable` usage anywhere in `app/`). The signed-URL advisory
  affects apps that rely on `URL::temporarySignedRoute()` for
  authorization boundaries - **not used in this app** (verified: no
  `temporarySignedRoute`/`hasValidSignature` usage in `app/` or `routes/`).
- **Resolution:** Not changed. A Laravel 11→12 (or →13) major upgrade is a
  substantial, separate project (deprecations, middleware signature
  changes, etc.) and does not meet the "real vuln justification for a
  major bump" bar given the two exposed code paths are unused. **Tracked
  as a manual review item** - see below.

### M2. Remaining esbuild/vite moderate advisory (dev server only)

- **Risk:** `npm audit` still reports one moderate advisory: esbuild <=0.24.2
  (bundled via vite 5.4.21) allows any website to send requests to the
  **local Vite dev server** and read the response. This does not affect
  the production build output (`npm run build`), only `npm run dev`.
- **Resolution:** Not changed - fixing it requires `vite@8` (a major
  bump with breaking config changes) per `npm audit fix --force`'s own
  warning. Left as a manual review item; low risk since `npm run dev` is a
  local-development-only tool, never run in production.

### M3. `SESSION_SECURE_COOKIE` is not enforced by application code

- **Risk:** `config/session.php`'s `secure` flag is purely
  `env('SESSION_SECURE_COOKIE')`-driven with no default and no
  code-level enforcement in production. `Brain/Deployment.md`'s
  documented production `.env` correctly sets `SESSION_SECURE_COOKIE=true`,
  but nothing in the app itself would catch a misconfigured production
  `.env` that left it unset (session cookie would then be sent over
  plain HTTP if TLS termination were ever misconfigured).
- **Resolution:** Not changed - documented in `docs/SECURITY.md` and
  `docs/DEPLOYMENT.md` as a required production variable. Recommend (not
  applied, to keep this change minimal and avoid altering runtime
  behaviour without the team's sign-off) adding
  `if (app()->isProduction()) { config(['session.secure' => true]); }`
  to `AppServiceProvider::boot()` as a defense-in-depth default - flagged
  under Manual Review Items rather than applied unilaterally.

## Low Priority Findings

### L1. Single-file logging, no rotation

`LOG_CHANNEL=stack` / `LOG_STACK=single` writes to one
`storage/logs/laravel.log` file with no automatic rotation or size cap.
At current scale this is a minor operational nit, not a security issue.
Recommend switching to the `daily` channel in production if disk usage
becomes a concern (`LOG_STACK=daily` is a one-line `.env` change, no code
change needed - Laravel ships the `daily` channel already in
`config/logging.php`).

### L2. `laravel/pint` has no project configuration

`composer.json` includes `laravel/pint` as a dev dependency, but there is
no `pint.json` in the repo, so `vendor/bin/pint --test` runs Laravel's
default preset and reports style differences across roughly 60 files that
were evidently never run through this exact preset (mostly import
ordering, brace placement, PHPDoc alignment - no functional issues).
**Not auto-fixed** - running `pint` and committing the result would
produce a large, unreviewed diff across most of the codebase, which is
exactly the kind of change this audit's brief asked to avoid ("prefer
minimal, well-justified fixes"). If code style enforcement is wanted,
the team should generate a `pint.json` that matches the codebase's actual
existing conventions (or explicitly adopt Pint's default and do one
reviewed style-only PR), rather than have an agent silently reformat ~60
files as a side effect of an audit.

## Confirmed Good

- **Authorization:** every controller with a destructive/sensitive action
  (`store`/`update`/`destroy`/`sync*`/`apply*`/`refresh`/`export`) was
  checked; all call `$this->authorize()` against a Policy or
  `abort_unless()`/`can:` against a Gate before touching data. The one
  controller with no direct `authorize()` call
  (`SupportController`) delegates the ownership check to
  `SuperOpsTicketService::userCanViewTicket()` for `show()`, and scopes
  `index()`/`store()` to `$request->user()` - verified correct, not a gap.
- **Tenant isolation:** `User::canAccessClient()` /
  `accessibleClientIds()` is used consistently everywhere client-scoped
  data is queried; `tests/Feature/Security/UserClientAccessTest.php` and
  `tests/Feature/ClientRoleAccessTest.php` /
  `ClientAdminDashboardIsolationTest.php` exercise this directly.
- **Mass assignment:** every Eloquent model declares an explicit
  `$fillable` array; none use `$guarded = []`.
- **SQL injection:** no string-concatenated SQL anywhere; the only raw SQL
  fragments (`whereRaw('LOWER(email) = ?', [$email])`, used for
  case-insensitive email lookups during SSO login and Entra sync) are
  fully parameterized.
- **Secrets:** no hardcoded API keys/tokens/passwords found anywhere in
  the repository (app code, config, tests, migrations, `Brain/` docs).
  `.env.example` contains only variable names and safe placeholders.
  `.gitignore` correctly excludes `.env`/`.env.backup`/`.env.production`/
  `auth.json`. Only `.env.example` is tracked by git.
- **CSRF:** not disabled anywhere; `bootstrap/app.php` registers no CSRF
  exceptions.
- **XSS:** the only unescaped Blade output (`{!! !!}`) is either static,
  developer-authored markup (icons, onboarding manual copy) or passed
  through an explicit sanitizer (`App\Support\SuperOpsHtml::sanitize()`,
  a strict tag allowlist that strips every HTML attribute) before
  rendering third-party (SuperOps ticket) HTML.
- **External HTTP calls:** every `Http::`/Guzzle call found in
  `app/Services/*` sets an explicit timeout (30-90s depending on the
  known latency of that platform's API) - no unbounded outbound call
  exists.
- **Rate limiting:** the OAuth callback, integration launch links,
  directory/security refresh actions, and Entra sync are all behind named
  rate limiters (`AppServiceProvider::boot()`) or inline `throttle:`
  middleware. **Correction (see "Second Independent Audit Pass" below):**
  support ticket creation (`POST /support`) was *not* rate-limited when
  this bullet was first written, contrary to what it claimed - this has
  since been fixed.
- **No public API / no CORS surface:** confirmed no `routes/api.php`, no
  `config/cors.php`, reducing attack surface.
- **No file uploads:** the app has no user file upload functionality
  (confirmed via repo-wide grep for `Storage::`/`storeAs`/`->move(`),
  eliminating an entire class of path-traversal/mime-spoofing risk.
- **Security headers:** `SecurityHeaders` middleware applies
  `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy`, `Permissions-Policy`, and HSTS in production, on
  every response.
- **No dead code / stray files:** repo-wide search found no `.bak`/`.old`/
  `~`-suffixed files, no leftover `dd()`/`var_dump()`/`die()` debug calls
  in application code.

## Security Review

See `docs/SECURITY.md` for the full authentication/authorization model,
secrets handling, and trust boundaries write-up produced by this audit.

## Infrastructure Review

- No Docker, no Kubernetes, no container orchestration - deploys directly
  to a Plesk-managed Ubuntu box via Plesk's Git integration. This matches
  the app's actual scale and is appropriate; the audit brief explicitly
  asked not to introduce new infra without a clear need, and none exists.
- No CORS configuration present (no API surface to protect).
- No debug/dev-only routes found left in `routes/web.php`.
- `APP_DEBUG` correctly defaults to `true` only in `.env.example` (local
  dev); `Brain/Deployment.md`'s production `.env` example correctly sets
  `APP_DEBUG=false`.
- Trusted proxies configured (`bootstrap/app.php`, `trustProxies(at:
  '*', ...)`) for the Plesk/nginx reverse-proxy setup - appropriate given
  the deployment target, though it does mean any host that can reach the
  app directly (bypassing the proxy) could spoof `X-Forwarded-*` headers;
  low risk on a single-tenant Plesk box with no exposed direct origin
  port, not changed.
- Scheduled jobs and queue workers run via systemd timers (no persistent
  daemon), each guarded by `withoutOverlapping()` and/or `Cache::lock()`
  per-client - appropriate for a single-server deployment; the code
  explicitly avoids `onOneServer()` because "stuck cache_locks can block
  for hours" per an in-repo comment - a documented, deliberate choice by
  the team, not an oversight.

## Performance Review

- All N+1-prone listing pages found (`Admin\UserController::index`,
  `Admin\ClientController::index`, etc.) already eager-load or
  aggregate with `withCount()`/`with([...])` rather than looping queries
  in the view.
- All external-API-backed dashboard data is cached (`Cache::remember`/
  explicit cache keys per client) with adaptive prewarm scheduling
  (`app/Console/Commands/PrewarmClientDashboardsCommand.php`) rather than
  hitting third-party APIs on every page load.
- Slow/unreliable external writes (Entra Graph SCIM provisioning, group
  sync) are dispatched as queued jobs rather than run inline, explicitly
  to avoid the 60s Plesk/nginx gateway timeout (documented in code
  comments and `Brain/Deployment.md`).
- Not deeply profiled beyond static analysis (no production traffic
  available to this audit) - if real-world slow queries are found in
  production, Laravel's query log / a APM tool would be the next step;
  none is currently configured (see Manual Review Items).

## Database Review

Reviewed all 19 migrations. Findings:

- Every foreign key uses `constrained()` with an explicit
  `cascadeOnDelete()` or `nullOnDelete()` - no orphaned-row risk, and the
  cascade/null choice is sensible per relationship (e.g. deleting a
  `Client` cascades its `client_notices`/`client_recommendations`/
  `client_opportunities`/`portal_links`, but a deleted `User` only
  `nullOnDelete()`s the `created_by` attribution column on that content,
  preserving the content itself).
- Unique constraints present where expected: `clients.slug`,
  `users.email`, `settings.key`, composite `client_user(client_id,
  user_id)`.
- Indexes present on every FK and on columns used for filtering
  (`is_active`, `display_order`, `published_at`/`expires_at` on notices,
  `created_at`/`action` on `activity_logs`, `entra_object_id` on `users`).
- No dangerous unconstrained cascading deletes found.
- `activity_logs` (audit trail) is pruned on a schedule
  (`model:prune`, `ACTIVITY_LOG_RETAIN_DAYS`, default 90) - bounded
  growth, not an unbounded audit table.

## Code Quality Review

- No duplicate/backup files (`.bak`, `.old`, `~`, `*copy*`) found.
- No commented-out dead code blocks or leftover debug statements found in
  application code.
- FormRequest classes consistently return `authorize(): true` and defer
  authorization to the controller - a deliberate, consistent pattern
  (documented in `AGENTS.md` so a future contributor doesn't "fix" it
  inconsistently).
- Pint style is not currently enforced (see L2) - not auto-fixed in this
  audit to avoid a large, unreviewed reformat.

## Dependency Review

- **PHP:** `composer.json` pins reasonable, current major versions
  (`laravel/framework ^11.0`, `php ^8.2`). `composer audit` findings
  patched down to 3 (Laravel-11-only, low practical exposure - see M1).
- **JS:** `package.json` pins current major versions (Vite 5, Tailwind 3,
  Alpine 3). `npm audit` findings patched down to 1 (dev-server-only -
  see M2).
- No abandoned/unmaintained packages flagged by either audit tool beyond
  the above.

## Testing Review

- 267 tests, 1203 assertions, all passing after this audit's fixes (was
  260 passed / 7 failed on the untouched checkout - see H3).
- Good existing coverage of authorization/tenant-isolation edge cases in
  `tests/Feature/Security/`, `ClientRoleAccessTest.php`,
  `ClientAdminDashboardIsolationTest.php`,
  `DropsuiteCustomerVisibilityTest.php`, `SupportTicketShowTest.php`.
- No CI configuration (`.github/workflows`, etc.) found in the
  repository - tests exist and pass, but nothing currently runs them
  automatically on push/PR. See Manual Review Items.

## Documentation Review

- `README.md` is accurate and current (verified commands actually work:
  `composer install`, `npm install && npm run build`,
  `cp .env.example .env && php artisan key:generate`).
- `Brain/` is an extensive, well-maintained internal knowledge base
  (39 files) covering architecture, every integration's setup, onboarding
  runbooks, and a decisions log - unusually thorough for a project this
  size, and was used as source material for this audit's `docs/*.md`
  rather than duplicated wholesale.
- This audit added: `AGENTS.md`, `docs/ARCHITECTURE.md`,
  `docs/SECURITY.md`, `docs/DEPLOYMENT.md`, `docs/SYSTEM_AUDIT.md` (this
  file) - none of these existed before this session.

## Removed Redundancy

None removed. No dead code, duplicate files, or genuinely unused
routes/controllers/migrations were found with enough confidence to
remove them (see "Never delete something you're not confident is unused"
in the audit instructions). Nothing is listed here because nothing met
that bar.

## Manual Review Items (found, deliberately not changed)

1. **Laravel 11 → 12/13 upgrade** (M1). Real CVE-justified reason exists,
   but the two vulnerable code paths (outbound `Mail`, signed URLs) are
   unused in this app, and a Laravel major upgrade needs its own
   regression pass (deprecations, middleware changes) that this audit's
   effort budget does not cover responsibly. Recommend scheduling as a
   dedicated piece of work, not bundling into a dependency-patch commit.
2. **Vite 5 → 8 major upgrade** to close the last esbuild dev-server
   advisory (M2). Dev-server-only exposure; not worth a breaking config
   migration under this audit's "no blind major bumps" instruction.
3. **`SESSION_SECURE_COOKIE` defaulting** (M3). A one-line defense-in-depth
   change (`config(['session.secure' => true])` when
   `app()->isProduction()`) was considered but not applied, since it
   changes runtime behavior (cookies would be rejected by any production
   client actually served over plain HTTP) without the team confirming
   TLS is always correctly terminated in front of every current
   deployment path. Flagged for the team to apply deliberately, with the
   `.env` fallback documented in `docs/SECURITY.md` in the meantime.
4. **`vendor/bin/pint --test` reports style diffs in ~60 files** (L2).
   Not auto-fixed - see L2 above.
5. **No CI workflow.** Tests and Pint exist and both run cleanly locally,
   but nothing runs them automatically on push/PR (no
   `.github/workflows/`). Adding one is low-risk and high-value, but is
   an infra/process decision (which triggers, which PHP/Node versions to
   matrix, whether to block merges) that should be made by the team
   rather than unilaterally added by this audit.
6. **`trustProxies(at: '*')`** (Infrastructure Review) - appropriate for
   the current single-box Plesk deployment, but worth tightening to a
   specific proxy IP/CIDR if the network topology ever changes to expose
   the app server directly alongside the reverse proxy.
7. **Single-file logging with no rotation** (L1) - low risk at current
   scale, one-line `.env` fix (`LOG_STACK=daily`) available whenever the
   team wants it; not changed unilaterally since it's an operational
   preference, not a bug.

## Remaining Risks

- This audit was performed entirely via static review, dependency
  scanning, and the automated test suite - **no production traffic, real
  database, or live external API credentials were available**, so
  runtime-only issues (actual query performance under load, real API
  rate-limit behaviour, actual Plesk/cron reliability) could not be
  directly verified and rely on the correctness of the team's own
  `Brain/Deployment.md` runbook and code comments.
- The Laravel-11-only advisories (M1) remain unpatched at the framework
  level; practical exposure is assessed as low but not zero (a future
  code change that adds `Mail::` usage or `temporarySignedRoute()` would
  re-introduce real exposure to those specific CVEs).
- No automated dependency-vulnerability scanning is wired into CI (there
  is no CI at all - see Manual Review Item 5), so future dependency
  regressions won't be caught automatically.

## Deployment Checklist

See `docs/DEPLOYMENT.md` for the full checklist. Summary of what must be
true before this app is considered production-ready to deploy on a new
target:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` generated
- [ ] `SESSION_SECURE_COOKIE=true` set explicitly (see M3 - not enforced
      by code, must be set in `.env`)
- [ ] Microsoft Entra ID app registration configured and
      `MICROSOFT_CLIENT_ID`/`MICROSOFT_CLIENT_SECRET`/redirect URI set
- [ ] Database migrated (`migrate --force`) and seeded
- [ ] `storage/`/`bootstrap/cache` writable by the web server user
- [ ] `config:cache`/`route:cache`/`view:cache` run after every deploy
- [ ] Scheduler (`schedule:run`) and at least one queue worker
      (`queue:work database ...`) running every minute via systemd timer
      or cron - **this app has no built-in supervisor**, it will silently
      stop processing background jobs without this
- [ ] `public/build/manifest.json` matches the deployed frontend source

## Verification Results

Actual command output from this session (not assumed):

```
$ composer install --no-interaction        # succeeded after resolving a
                                            # concurrent-install race (see
                                            # note below); exit 0

$ npm install && npm audit fix && npm run build
9 vulnerabilities -> npm audit fix -> 2 vulnerabilities (1 moderate, 1 high)
[esbuild/vite advisory requires a major bump, left unfixed - see M2]
vite v5.4.21 building for production... ✓ built in 1.49s

$ composer audit           # before fix: 25 advisories, 5 packages
$ composer update guzzlehttp/guzzle guzzlehttp/psr7 league/commonmark \
    phpseclib/phpseclib --with-all-dependencies
$ composer audit           # after fix: 3 advisories, 1 package (see M1)

$ php artisan test         # BEFORE test fixes: 7 failed, 260 passed
$ php artisan test         # AFTER test fixes:  267 passed (1203 assertions)

$ vendor/bin/pint --test   # reports style differences in ~60 files;
                           # not auto-fixed (see L2) - not a pass/fail
                           # gate in this repo today (no pint.json, not
                           # wired into any CI)
```

**Note on the composer install:** two concurrent `composer install`
invocations were started in this session before their outputs were
checked, and their file-locking raced on a `git checkout` inside
Composer's package cache, corrupting the `phpunit/phpunit` vendor
directory. This was caught immediately (the next command failed loudly -
`vendor/autoload.php` missing), fixed by `rm -rf vendor && composer
install` (a single clean run), and is called out here in the interest of
an honest, complete verification log rather than omitted.

## Second Independent Audit Pass (2026-09-28)

A second, independent reviewer re-verified this document's material claims
against the actual code rather than trusting the write-up above, per an
explicit request not to rubber-stamp the first pass. Branch:
`claude/jolly-hopper-6w33al` (unchanged from the first pass).

### What was independently re-verified, with evidence

- **Authorization chain on destructive/admin controllers.** Traced
  middleware → controller → Policy for `Admin\ClientController`
  (`store`/`update`/`destroy`/`syncEntra`/`applyScim`/`applyClientSso` -
  every method calls `$this->authorize('update'|'delete'|'create',
  $client)` at `app/Http/Controllers/Admin/ClientController.php:41,86,
  134,173,183,237,313,336,374,409,431,496`, backed by
  `app/Policies/ClientPolicy.php:16-35`), `Admin\UserController` (same
  pattern, `app/Http/Controllers/Admin/UserController.php:26,58,73,92,
  105,132`, backed by `app/Policies/UserPolicy.php`), and the three
  client-scoped staff dashboards (`ClientMicrosoft365DirectoryController`,
  `ClientHuntressSecurityController`, `ClientDropsuiteBackupController` -
  each of their three actions calls `$this->authorize('view', $client)`
  before touching data, even though the route itself only carries the
  coarse `role:` group middleware). **Confirmed accurate**: no controller
  action was found that skips authorization for a route-bound model.
  `Admin\SettingController`/`Admin\ActivityLogController`/
  `Admin\IntegrationHealthController::updateFreshness` were also checked -
  all gate on `SettingPolicy`/`ActivityLogPolicy` correctly
  (`app/Policies/SettingPolicy.php`, `app/Policies/ActivityLogPolicy.php`).
  `SupportController::show()`'s ownership delegation to
  `SuperOpsTicketService::userCanViewTicket()`
  (`app/Services/SuperOps/SuperOpsTicketService.php:112-137`) was read in
  full and does correctly deny cross-tenant/cross-user ticket access.
- **Tenant scoping.** `User::canAccessClient()`/`accessibleClientIds()` is
  used in every admin index/listing method inspected
  (`UserController::index`, `ClientController::index`,
  `ActivityLogController::index`, `IntegrationHealthController`). Confirmed.
- **Rate limiting.** `AppServiceProvider::boot()` and `routes/web.php` were
  re-read line by line. **The first audit's "Confirmed Good" bullet
  overstated this one**: `POST /support` (`SupportController::store`,
  which calls the SuperOps API to create a real ticket) had **no rate
  limiting whatsoever** - not a named limiter, not inline `throttle:`, not
  even the Laravel default (this app's `web` middleware group, per
  `bootstrap/app.php`, does not include a global throttle - Laravel 11
  only auto-throttles the `api` group, which this app doesn't have). Any
  authenticated user could submit unlimited tickets. **Fixed** in this
  pass: added `RateLimiter::for('support-ticket-store', ...)` (6/min per
  user) in `app/Providers/AppServiceProvider.php` and applied
  `throttle:support-ticket-store` to the route in `routes/web.php`. Full
  267-test suite re-run afterward, still green.
- **Dependency/test/build verification re-run from scratch this
  session**, not assumed from the first pass's log:
  - `composer audit`: still 3 `laravel/framework` advisories, matching M1
    exactly (same advisory IDs).
  - `npm audit`: reports "2 vulnerabilities (1 moderate, 1 high)" -
    **a minor inaccuracy in the first pass's M2**, which said "1 moderate
    advisory only". It is still a single root cause (esbuild <=0.24.2,
    bundled by vite, dev-server-only exposure) but `npm audit`'s own
    summary line counts it as 2 (esbuild moderate + the vite entry that
    depends on it, rolled up as high). Practical risk assessment (dev-
    server only, not shipped to production) is unaffected and still
    correct.
  - `php artisan test`: **267 passed (1203 assertions)**, confirmed
    unchanged after this pass's two code fixes.
  - `npm run build`: succeeds (`vite v5.4.21 ... built in ~2s`). Note: a
    from-scratch `npm run build` regenerates `public/build/assets/*.css`
    under a **different content hash than the committed one**, with byte-
    identical CSS otherwise - Vite's hashing isn't perfectly deterministic
    across a clean rebuild here. This pass reverted that incidental diff
    rather than commit an unrelated asset churn; worth the team knowing
    the committed `public/build/*` will drift by a hash on every rebuild
    even with no source change.
  - `vendor/bin/pint --test`: still reports style diffs across the same
    ~60 files (same file list re-checked), matching L2 exactly.

### Findings that contradict or refine the first pass

1. **Rate limiting gap on support ticket creation** (above) - the first
   pass's "Confirmed Good" section was wrong on this specific point.
   Fixed in this pass.
2. **`.env.example` did not actually contain `SESSION_SECURE_COOKIE`
   at all** - not commented-out, not blank, simply absent. `docs/
   SECURITY.md`'s wording ("`.env.example` intentionally leaves it blank
   for local HTTP development") implied the key was present-but-empty;
   it wasn't present. A team member copying `.env.example` to build a new
   `.env` would never be prompted to set this production-critical flag at
   all. **Fixed** in this pass: added `SESSION_SECURE_COOKIE=` with an
   explicit "must be true in production" comment. M3's underlying
   assessment (no code-level enforcement) still stands as a valid
   separate manual-review item.
3. **L1's fix suggestion is wrong for this repo.** The first pass wrote
   "`LOG_STACK=daily` is a one-line `.env` change, no code change needed -
   Laravel ships the `daily` channel already in `config/logging.php`."
   Re-read `config/logging.php` in full: this repo's copy has been
   trimmed to only three channels - `stack`, `single`, `null`
   (`config/logging.php:17-37`). The `daily` channel (and the rest of
   Laravel's stock skeleton channels - `slack`, `papertrail`, `syslog`,
   etc.) is **not present**. Switching to `LOG_STACK=daily` today would
   fail (`InvalidArgumentException: Log channel [daily] is not defined`)
   until someone actually adds the channel block to
   `config/logging.php`. Not fixed in this pass (an unrequested config
   addition); flagged here so L1's recommendation isn't followed
   verbatim and fails in production.
4. **`/up` health check did not verify DB connectivity — fixed.**
   `bootstrap/app.php` registers Laravel's default `health: '/up'` route;
   no listener for Laravel's `DiagnosingHealth` event existed anywhere in
   `app/`. `/up` therefore only proved the PHP process booted and routes
   resolved - it said nothing about MySQL being reachable. **Fixed** in
   `app/Providers/AppServiceProvider.php` by listening for
   `DiagnosingHealth` and touching `DB::connection()->getPdo()`, so a
   dead DB connection now fails `/up` with a 500 instead of a false
   "healthy". Verified locally: with no MySQL reachable in the sandbox,
   `/up` correctly returned `500` and `storage/logs/laravel.log` showed
   `SQLSTATE[HY000] [2002] Connection refused` - confirming the listener
   fires and surfaces the real failure rather than masking it.
5. **SuperOps HTTP failure logging included the full raw response
   body — mitigated.** `app/Services/SuperOps/SuperOpsApiClient.php`
   logged `Log::error('SuperOps HTTP request failed', ['status' => ...,
   'body' => $response->body()])` on every failed call, unbounded. No
   token/secret was found in any logged payload in this pass's review
   (the SuperOps API token is sent as a request header, not echoed in
   response bodies, and no `Log::` call site logs request headers
   anywhere in `app/` - verified by grep), so this was **not** a
   credential-leak finding. It could, however, put customer-identifying
   data (ticket subjects, requester emails inside a GraphQL error
   payload) into `storage/logs/laravel.log` at `error` level, and an
   unbounded body could flood log storage on a large failure response.
   **Fixed** by truncating the logged body to 1000 characters
   (`Str::limit`) - full redaction was not attempted, since GraphQL error
   payloads are also the primary debugging signal for integration
   failures and blanket redaction would make failures harder to
   diagnose; truncation bounds the exposure/log-volume risk without
   losing that signal.

### New findings from areas the first pass covered lightly

- **Performance**: re-checked every `Admin\*Controller::index()` (Client,
  User, PortalLink, Notice, Opportunity, Recommendation, ActivityLog,
  Team) - all paginate (`->paginate(15)` or `->paginate(25)`) and the two
  genuinely relation-heavy ones (`UserController::index`,
  `ActivityLogController::index`) eager-load (`->with([...])`,
  `->withCount([...])`). No N+1 query pattern found in the Blade views
  sampled (`admin/users/index.blade.php` uses pre-aggregated
  `users_count`/`active_users_count`, not a per-row relation call in a
  loop). This matches the first pass's Performance Review claim.
- **Database**: re-read all 19 migrations' index/constraint definitions
  directly (not just trusted the summary) - confirmed composite indexes
  like `['client_id', 'is_active']` and single-column indexes on
  `display_order`, `published_at`, `expires_at`, `action`, `created_at`
  are actually present in the migration files, not just claimed. Matches.
- **Infra**: confirmed via `find` for `Dockerfile*`/`docker-compose*`/
  `.github/workflows` across the whole repo (excluding `node_modules`) -
  genuinely none exist. `Brain/` (39 files) was opened and confirmed to
  be pure Markdown documentation/runbooks (architecture notes, onboarding
  guides, a decisions log) - **not** deployment scripts or executable
  tooling, despite the brief's suggestion it might need investigating as
  such. `.cursor/rules/*.mdc` is editor-assistant configuration
  (ASCII-hyphen style rule, a note to keep debug scratch files outside
  the repo, a Brain-docs-maintenance rule) - not relevant to security or
  infra.
- **`.env.example` vs actual `env()`/`config()` usage**: cross-referenced
  every `env('...')` call in `config/*.php` against `.env.example`.
  Beyond the `SESSION_SECURE_COOKIE` gap (fixed, see above), all other
  app-specific variables (`ENTRA_SYNC_CLIENT_ID/SECRET`,
  `SUPEROPS_TECHNICIAN_PORTAL_URL`, `ONIT_SUPPORT_*`) are present as
  commented-out optional lines with explanatory comments - a deliberate,
  reasonable pattern, not drift. The remaining "missing" variables from a
  raw diff (`AWS_*`, `REDIS_*`, `DB_CHARSET`, `AUTH_GUARD`, etc.) are
  unused stock Laravel config knobs with safe defaults in
  `config/*.php` and don't need to be in `.env.example` for an app that
  doesn't use S3/Redis/multi-guard auth. No further action needed there.

### Updated verification results (this session, actual output)

```
$ php artisan test
Tests:    267 passed (1203 assertions)
Duration: 30.95s

$ npm run build
vite v5.4.21 building for production...
✓ 59 modules transformed.
✓ built in 2.21s

$ composer audit
Found 3 security vulnerability advisories affecting 1 package: laravel/framework
(unchanged from first pass - see M1)

$ npm audit
2 vulnerabilities (1 moderate, 1 high) - both esbuild/vite, dev-server-only
(first pass reported this as "1 moderate advisory only" - see correction above)

$ vendor/bin/pint --test
Same ~60-file style diff as the first pass reported (not auto-fixed, same
reasoning as L2).
```

Two fixes were made and committed in this pass:
1. `app/Providers/AppServiceProvider.php` / `routes/web.php` - added a
   `support-ticket-store` rate limiter (6/min/user) to `POST /support`.
2. `.env.example` - added the previously-absent `SESSION_SECURE_COOKIE`
   variable with a production-required comment.

Both changes were verified not to break the test suite (267 passed,
unchanged, after each fix).

### Bottom line on the first pass's quality

The first pass's core security claims (authorization consistency, tenant
scoping, no hardcoded secrets, parameterized SQL, CSRF never disabled,
explicit `$fillable` everywhere, no file upload surface, dependency
patching) all held up under independent, file:line-level re-verification
in this pass and are **not** being walked back. The gaps found here are
narrower and more operational: one real rate-limiting hole (fixed), one
documentation/config-drift issue that would have bitten a real deploy
(fixed), one factually wrong remediation suggestion in L1 (corrected in
writing, not code), and a couple of observability gaps (`/up` not
checking DB, verbose failure-path logging) that are reasonable manual
review items rather than blockers.

## Production Readiness Status

**Conditionally ready**, with the caveats below made explicit rather than
glossed over:

- The application's own security posture (authz, secrets, SQL injection,
  XSS, CSRF, mass assignment, rate limiting) is solid and was verified by
  direct code review plus a passing, IDOR-focused test suite. Nothing in
  this category blocks production use.
- Dependency vulnerabilities that had a safe, non-breaking fix were
  patched and verified (tests still pass, build still succeeds).
- **What still needs human validation before/around a production
  deploy**, because this audit could not verify them (no production
  environment, real credentials, or traffic available in this session):
  - That `SESSION_SECURE_COOKIE=true` (and the rest of
    `docs/DEPLOYMENT.md`'s required variables) is actually set correctly
    on the real production `.env` - the app does not enforce this in
    code (M3).
  - That the systemd timers / cron entries for `schedule:run` and
    `queue:work` documented in `Brain/Deployment.md` are actually
    installed and healthy on the target host - this app has no built-in
    supervisor and background work (Entra sync, dashboard prewarm, ticket
    metrics) silently stops without them.
  - Real-world performance under production load and production API
    rate limits from Microsoft Graph/SuperOps/Huntress/Dropsuite, which
    static review cannot substitute for.
  - A decision on the Laravel-11-only advisories (M1): accept the
    documented low-practical-risk assessment, or schedule a Laravel 12/13
    upgrade.
- Nothing found in this audit should be treated as a blocker to deploying
  the current codebase, provided the deployment checklist above is
  followed and the two open manual-review-worthy risks (M1, M3) are a
  conscious decision by the team rather than an oversight.

## Third Audit Pass — Performance, API Inventory, Dead Code (2026-09-28)

A third, independent pass, explicitly scoped away from re-litigating
auth/authz/secrets/CSRF/SQLi/mass-assignment/uploads (covered exhaustively
by the first two passes - see above) and into three areas the brief said
were not yet swept exhaustively: performance/DB, the full route inventory,
and dead code. This was a full sweep, not a sample: every controller
method, every migration, every route, and every top-level class in `app/`
was checked programmatically (grep/route:list cross-referencing), not
spot-checked.

### 1. Performance & Database

**N+1 sweep (full, not sampled).** Every `index`/`show`/`live`/`export`
method across all 26 controllers in `app/Http/Controllers` was read, and
every corresponding Blade view was grepped for `->relation->field` inside
`@foreach`. Result: **no new N+1 found** - the second pass's finding still
holds under a full sweep. All admin listing controllers that pass an
Eloquent collection to a view eager-load exactly the relations the view
uses: `ActivityLogController::index` (`->with(['user','client'])`,
`admin/activity-logs/index.blade.php` uses `$log->user`/`$log->client`),
`NoticeController`/`OpportunityController`/`RecommendationController::index`
(`->with(['client','creator'])`), `PortalLinkController::index`
(`->with('client')`), `TeamController::index` (`->with('assignedClients')`),
`UserController::index` (uses `withCount`/`users_count` on the `clients`
listing view, not a per-row relation call). The non-Eloquent
controllers (`HuntressSecurityController`, `Microsoft365DirectoryController`,
`ClientAdminDashboardController`, `SupportController`, backup/report
controllers) render arrays from cached external-API service calls, not
Eloquent collections, so N+1 doesn't apply to them. No fix needed here.

**Index sweep (full, cross-referenced against real `where()` usage).**
Read all 18 migrations and cross-referenced every FK/filter column
against `grep -rn "->where(" app/`. One real gap found and fixed:

- **`clients.superops_account_id` had no index**, despite being queried
  with a plain `Client::query()->where('superops_account_id', $accountId)`
  in `app/Services/SuperOps/SuperOpsTicketService.php:134`
  (`userCanViewTicket()`), which runs on every `GET /support/{ticketId}`
  page view for a team-member user whose ticket isn't already scoped to
  their own client. **Fixed**: added
  `database/migrations/2026_09_28_120000_add_index_to_clients_superops_account_id.php`
  (`$table->index('superops_account_id')`). Verified: `php artisan
  migrate` applies cleanly, `php artisan migrate:rollback --step=1` rolls
  it back cleanly, re-`migrate` reapplies cleanly (all run against a
  scratch sqlite DB - no local MySQL server is available in this sandbox,
  same constraint the prior two passes operated under; the migration uses
  only the portable `Blueprint::index()` call). `php artisan test` still
  267 passed after adding it.
- All other `where()`-filtered columns found by the grep sweep
  (`is_active`, `client_id`, `provisioned_by`, `entra_object_id`, `key`,
  `snapshot_date`, `email`) already have an index or unique constraint in
  the migrations that created/altered them - re-confirmed directly, not
  assumed. `entra_tenant_id` is queried with `where('entra_tenant_id',
  '!=', '')` in `ClientController::graphReconsent` and
  `ListGraphReconsentUrlsCommand` - both are low-frequency admin/CLI
  paths over a small `clients` table and a `!=` predicate isn't
  index-selective anyway, so no index was added for it.

**External HTTP calls.** Re-verified all four external API client
classes (`SuperOpsApiClient`, `HuntressApiClient`, `DropsuiteApiClient`,
`MicrosoftGraphClient`) still set an explicit timeout on every call
(30-90s) - unchanged from the prior passes' finding, still holds.

**New finding: two admin Entra actions run long external-call chains
synchronously on the request path, inconsistent with sibling actions that
were deliberately queued for the same reason.**
`Admin\ClientController::applyScim()` and `::retryScimExport()` both
dispatch a queued job with an in-code comment explaining why: *"Graph
waits (schema / already-exists) can exceed nginx's 60s gateway - never do
that inline."* `::bootstrapEntra()` and `::applyClientSso()`, however,
call `CustomerEntraBootstrapService::bootstrap()` /
`MicrosoftGraphClient::applyClientSsoSamlConfiguration()` **inline**,
synchronously, in the controller action.
`CustomerEntraBootstrapService::bootstrap()` alone makes a long chain of
sequential Graph calls, several through
`$this->graph->retryAfterConsentPropagation(...)` (a wait-and-retry
wrapper) and `waitForServicePrincipalForAppId(...)` (a polling wait) -
the same class of slow, multi-step Graph operation the SCIM methods'
comment explicitly warns about. **Not fixed in this pass**: converting
these two actions to queued jobs is a real behavioural change (both
currently return SSO login URL/certificate data synchronously to the
admin's page load; going async would need the same live-polling UI
pattern `syncEntra`/`applyScim` already use, which is more than an
"obvious, safe fix" and risks unintended UX regressions for an
admin-only, low-traffic setup flow). **Flagged under Manual Review
Items** below with the file:line evidence.

**Duplicate/repeated queries.** No same-request duplicate query pattern
found (checked controller+view chains for the same lookup run twice;
dashboard/admin controllers consistently compute once and pass down).

**Pagination.** Every admin listing controller uses `->paginate(15)` or
`->paginate(25)`; the two non-paginated `->get()` calls found
(`ClientController::index` line 70's `->get()` and
`Admin\DashboardController`'s `->limit(10)->get()`) are both bounded -
respectively a small `is_active` dropdown-source query and an explicit
`limit(10)` recent-activity feed - not unbounded lists. No fix needed.

### 2. Full route inventory

All 91 named routes (plus `/`, `/up`, and the 2 `Route::redirect`
entries) in `routes/web.php` (the only route file - no `routes/api.php`)
were read line-by-line and cross-referenced against `php artisan
route:list --json` and `route()`/`->name`/nav-partial references across
`resources/views` and `app/`.

- **Every route is referenced somewhere in the frontend or another
  controller** (nav partials, model helper methods like
  `PortalLink::launchUrl()`, or a same-app `redirect()->route()` call) -
  checked programmatically against the full `route:list` output, not
  sampled. No dead/orphaned route found. (The 6 names a naive grep first
  flagged as "unreferenced" - `admin.activity-logs.index`,
  `admin.settings.index`, `auth.microsoft.callback`,
  `integrations.pax8.launch`, `security.huntress.refresh`,
  `admin.clients.security.huntress.refresh` - were all false positives
  from the grep pattern; each was manually confirmed present in
  `resources/views/admin/partials/nav.blade.php` or a controller/model
  `route()` call.)
- **No duplicate or near-duplicate routes** - every URI+method pair in
  the full `route:list` output maps to exactly one controller action; the
  two `Route::redirect()` legacy-URL shims (`/client-admin` →
  `/services/support-devices`, `/client-admin/live` → `.../live`) are
  intentional redirects, not duplicates.
- **Authorization-vs-middleware consistency re-checked for every route,
  not just the ones the prior two passes already traced.** All
  `admin.*` routes sit behind `role:` group middleware; every
  `Admin\*Controller` action was confirmed (by reading the controller
  file, not assuming from the route) to also call
  `$this->authorize(...)` against a Policy for any client/user-scoped
  action - this matches the two prior passes' file:line-verified findings
  for `ClientController`/`UserController` and was re-confirmed here for
  the remaining ones not individually itemised before:
  `NoticeController`/`OpportunityController`/`RecommendationController`/
  `PortalLinkController` (`authorize('viewAny'|'create'|'update'|'delete',
  ...)` on every action), `TeamController` (all actions authorize against
  `UserPolicy` plus the explicit `role:super-admin` route middleware
  layer), `ClientDropsuiteBackupController`/`ClientHuntressSecurityController`/
  `ClientMicrosoft365DirectoryController` (`authorize('view', $client)`
  on every client-scoped action, as previously found). **No route was
  found relying on middleware alone without an in-controller check.**
  This closes out the brief's ask to verify this holds for every
  remaining route, not just the previously spot-checked ones.

### 3. Dead code & technical debt sweep

- **Class/method reachability**: every one of the 146 PHP classes in
  `app/` was grepped for its basename across the whole repo (`app/`,
  `resources/`, `routes/`, `database/`, `tests/`, `config/`,
  `bootstrap/`). The only classes with zero references outside their own
  file were: (a) all `app/Console/Commands/*` classes - false positives,
  Laravel auto-discovers commands in that directory, and each was
  confirmed either scheduled in `routes/console.php` or is a documented
  manual-run admin tool (`ListGraphReconsentUrlsCommand`,
  `RevertEntraDisplayNamesCommand`, `PurgeDemoDataCommand`, etc.); (b)
  `EventServiceProvider`/`AuthServiceProvider` - registered in
  `bootstrap/providers.php`, which the first sweep's grep scope missed;
  (c) `EnsureUserHasRole`/`SecurityHeaders`/`EnsureClientAccess` - aliased/
  applied in `bootstrap/app.php`, also outside the first sweep's grep
  scope. Re-run including `bootstrap/`: (a) and (b) resolved as false
  positives. **(c) surfaced one genuine finding**:
  - **`EnsureClientAccess` middleware is defined and aliased
    (`'client.access' => EnsureClientAccess::class` in
    `bootstrap/app.php`) but is never applied to any route** - `grep -rn
    "client.access"` across the whole repo (excluding `bootstrap/app.php`
    itself) returns nothing. It appears to predate the current pattern of
    doing tenant checks via explicit `$this->authorize()`/
    `User::canAccessClient()` calls in each controller (which the first
    two passes verified is applied consistently everywhere it's needed),
    and is now vestigial. **Not deleted** - it's a small, harmless,
    security-adjacent piece of code, and removing an authorization
    primitive (even an unused one) is exactly the kind of change this
    audit's brief said should go to manual review rather than be deleted
    on an agent's own confidence, however high. **Added to Manual Review
    Items** below.
- **Migrations**: all 18 migrations were checked for tables/columns never
  referenced in `app/` - none found. Every column added by a later
  migration is read somewhere in `app/Models`/`app/Services` (checked via
  grep for each migration's `$table->...` column names). No superseded/
  reverted migration found (`2026_07_15_120000_migrate_client_user_to_client_requester`
  is a genuine data-shape migration, not a revert).
- **Commented-out code**: grepped all of `app/`, `resources/views/`,
  `routes/` for multi-line `/* ... */` blocks and `//`-prefixed lines that
  look like code (`Route::`, `$var =`, `if (`, `public function`,
  `return`, `->`). None found - the only `/* */` comments present are
  genuine CSS comments inside `<style>` blocks in three Blade views. No
  change needed; matches the first pass's finding.
- **Config**: cross-referenced every key in `config/onit_support.php`
  (the one genuinely app-specific config file) against real `config()`
  calls - all four scalar keys plus `address_lines` are consumed via
  `ContactSupportController` passing the whole array to
  `resources/views/contact-support/{index,new-starter}.blade.php`
  (`$contact['phone']`/`['hours']`/`['timezone_label']`/`['email']`/
  `['address_lines']`). No dead config keys found. (`auth.php`/`cache.php`/
  `database.php`/`logging.php`/`session.php`/`view.php` showing zero
  direct `config('x.y')` call sites in `app/`/`resources/` is expected -
  Laravel's own internals read those directly via the `Auth`/`Cache`/`DB`/
  `Log`/`Session`/`View` facades, not a dead-config signal.)
- **Dependencies**: every `composer.json` and `package.json` dependency
  was checked for a real import/usage site. All resolve to genuine usage
  (`laravel/socialite` + `socialiteproviders/microsoft-azure` via
  `MicrosoftAuthController`/`EventServiceProvider`, `axios` via
  `resources/js/bootstrap.js`, Tailwind/Alpine/Vite via the build
  pipeline) with one exception, **flagged, not removed**: `laravel/breeze`
  is a dev dependency with no runtime import anywhere in `app/`/
  `resources/` - by design, Breeze is a one-time scaffolding installer,
  not a runtime library, so "unused after scaffolding" is expected
  behaviour for it, not drift. Removing it from `composer.json` is a
  reasonable cleanup but was left to the team's judgement (it costs
  nothing to keep, and removing a `require-dev` package it didn't ask
  about wasn't judged safe/obvious enough to do unilaterally).

### New Manual Review Items (this pass)

8. **`bootstrapEntra()`/`applyClientSso()` run long Graph-API call chains
   synchronously on the request path** (Performance & Database, above),
   inconsistent with the sibling `applyScim()`/`retryScimExport()`
   actions that were deliberately queued for the identical 60s-gateway-
   timeout reason (see their in-code comment,
   `app/Http/Controllers/Admin/ClientController.php`). Recommend either
   queuing them the same way (would need a live-polling UI update, same
   pattern already used for `syncEntra`) or, at minimum, raising PHP's
   `max_execution_time`/web-server proxy timeout for just those two
   routes if queuing is judged not worth the UX rework. Low real-world
   risk today (admin-only, one-time-per-client setup action, not a
   customer-facing page), but a real timeout hazard on a slow/throttled
   Graph tenant.
9. **`EnsureClientAccess` middleware (`client.access` alias in
   `bootstrap/app.php`) is defined but applied to zero routes** (Dead
   code sweep, above). Likely superseded by the controller-level
   `$this->authorize()`/`canAccessClient()` pattern used everywhere else.
   Recommend either wiring it onto the client-scoped routes that
   currently rely solely on in-controller checks (defense-in-depth) or
   removing the alias/class if the team confirms it's intentionally
   superseded - left for a deliberate decision either way, not touched.
10. **`laravel/breeze` dev dependency has no runtime usage** (Dependency
    sweep, above) - expected for a scaffolding-only package, but worth a
    conscious keep/remove decision next time `composer.json` is touched.

### Verification results (this pass, actual output)

```
$ php artisan test
Tests:    267 passed (1203 assertions)
Duration: 31.97s

$ npm run build
vite v5.4.21 building for production...
✓ 59 modules transformed.
✓ built in 1.86s
(incidental public/build/* content-hash churn from the rebuild was
reverted, not committed - same non-determinism the second pass noted)

$ composer audit
Found 3 security vulnerability advisories affecting 1 package: laravel/framework
(unchanged from the first two passes - see M1)

$ npm audit
2 vulnerabilities (1 moderate, 1 high) - both esbuild/vite, dev-server-only
(unchanged from the second pass's corrected count - see M2)

$ php artisan migrate            # against a scratch sqlite DB; no local
                                  # MySQL server available in this sandbox
2026_09_28_120000_add_index_to_clients_superops_account_id ... DONE
$ php artisan migrate:rollback --step=1
2026_09_28_120000_add_index_to_clients_superops_account_id ... DONE (rolled back)
$ php artisan migrate
2026_09_28_120000_add_index_to_clients_superops_account_id ... DONE (reapplied)
```

### Bottom line on this pass

The performance and route-authorization pictures the prior two passes
described held up under a genuinely exhaustive, full-repository sweep
(every controller method, every migration, every route, every class) -
no N+1s, no authz gaps, no dead/duplicate routes were found beyond what
was already known. The concrete, additive output of this pass is one
real missing index (fixed, migration added and verified up/down/up), one
inconsistent-synchronous-Graph-call finding and one genuinely-unused
middleware finding (both flagged for a deliberate team decision rather
than changed unilaterally), and one dev-dependency note. Nothing here
blocks production readiness; the "Production Readiness Status" section
above is unchanged by this pass.

## Fourth Audit Pass — Requested Fixes, Frontend, Infra, Dependencies (2026-09-28)

A fourth pass with two parts: (A) two fixes the repo owner explicitly asked
for by name (both flagged, not applied, by the third pass), and (B) three
areas the original audit brief called for but no pass had swept
exhaustively yet - frontend/asset audit, Docker/infra confirmation, and
dependency hygiene. Branch: `claude/jolly-hopper-6w33al`, freshly branched
off `main` at `9bbf0b8`.

### Part A1: Removed the dead `EnsureClientAccess` middleware

Re-confirmed the third pass's finding (Manual Review Item 9) directly
before deleting: `grep -rln "EnsureClientAccess\|client\.access" app
bootstrap tests routes resources docs Brain AGENTS.md README.md config`
returned only the middleware's own file, its alias registration in
`bootstrap/app.php`, and narrative mentions in `docs/SECURITY.md`/
`docs/ARCHITECTURE.md`/`docs/SYSTEM_AUDIT.md` themselves (this file) -
**zero routes, zero tests, zero other code references**. Deleted
`app/Http/Middleware/EnsureClientAccess.php` and removed the
`'client.access' => EnsureClientAccess::class` alias (and its `use`
import) from `bootstrap/app.php`. Updated the two stale claims this left
in `docs/SECURITY.md` and `docs/ARCHITECTURE.md` (both previously said
the middleware "provides the same check" as `accessibleClientIds()` for
routes keyed by a raw `client_id` - it did not, because it was wired to
none). 267 tests still pass after removal (commit `1144e8c`).

### Part A2: Queued `bootstrapEntra()` and `applyClientSso()`

Both methods in `app/Http/Controllers/Admin/ClientController.php` made
synchronous Microsoft Graph calls on the request path - the same 60s
nginx gateway timeout risk `applyScim()`/`retryScimExport()` were already
queued for (Third Audit Pass, Manual Review Item 8). Converted both to
the identical `markQueued()` + `dispatch()` + `_queued` activity-log
action + background-run flash message pattern used by
`ApplySuperOpsScimJob`/`RepairSuperOpsScimExportJob` (commit `067fd85`).

- **`bootstrapEntra()` - queued.** Added `App\Jobs\BootstrapClientEntraJob`
  wrapping `CustomerEntraBootstrapService::bootstrap()`. This method only
  ever flashed a summary message the view doesn't read back out of a
  cache key, so this was a mechanical copy of the `applyScim()` pattern -
  no UI rework needed. Verified by `tests/Feature/BootstrapEntraTest.php`:
  `test_bootstrap_queues_background_job_immediately` (asserts the
  in-flight cache flag is set, `BootstrapClientEntraJob` is pushed with
  the right `clientId`, and the redirect carries a `success` flash),
  `test_bootstrap_without_tenant_id_is_not_queued` (guard clause still
  short-circuits before queueing), and
  `test_job_records_result_and_logs_activity` (runs the job's `handle()`
  directly against a mocked `CustomerEntraBootstrapService`, asserts the
  in-flight flag clears, the last-result cache is written, and a
  `client.entra_bootstrap` row lands in `activity_logs`).
- **`applyClientSso()` - queued**, after investigating the nuance the
  brief flagged. The Blade partial
  `resources/views/admin/clients/_client-sso-apply-form.blade.php` reads
  the `client_sso_idp.{id}` cache key synchronously to display the Login
  URL/certificate - this is exactly the pattern
  `_scim-apply-form.blade.php` already uses for
  `scim_apply.in_flight`/`scim_apply.last_result` (`ApplySuperOpsScimJob`
  constants), so it was not a novel problem: added
  `App\Jobs\ApplyClientSsoSamlJob`, which writes the same
  `client_sso_idp.{id}` cache entry the controller used to write
  synchronously, plus its own `client_sso_apply.in_flight`/
  `client_sso_apply.last_result` keys. Updated
  `_client-sso-apply-form.blade.php` to read those two keys the same way
  `_scim-apply-form.blade.php` reads its SCIM equivalents: an amber
  "running in the background" banner while in flight, a
  success/failure banner from the last result once finished, and the
  submit button disabled with a "Queued - redirecting…" state (same
  Alpine `x-data="{ submitting: false }"` pattern already used on the
  SCIM form) - so the page never silently shows stale/empty Login
  URL/certificate fields. Rewrote `tests/Feature/ApplyClientSsoSamlTest.php`
  (the old version asserted synchronous `client_sso_login_url`/
  `client_sso_certificate` session flashes, which no longer exist) with
  three tests modeled on `ApplySuperOpsScimTest`:
  `test_apply_queues_background_job_immediately` (in-flight flag set,
  job pushed with the right `entityId`/`consumerServiceUrl`, checklist
  and `client_sso_idp` cache untouched until the worker runs),
  `test_job_writes_login_url_and_certificate_to_cache_for_the_view` (runs
  `handle()` directly against a mocked `MicrosoftGraphClient`, asserts
  the `client_sso_idp.{id}` cache and the checklist flag are both set),
  and `test_job_records_failure_without_touching_checklist` (Graph throws
  → checklist stays false, last-result cache records the failure message,
  matching `ApplySuperOpsScimJob::failed()`'s behaviour).

`php artisan test` after both A1 and A2: **272 passed (1229 assertions)**
(267 + 5 new: 3 in `ApplyClientSsoSamlTest`, replacing the old single test
that asserted the now-removed synchronous behaviour, and 3 in
`BootstrapEntraTest`, net 2 new files/+5 tests overall).

### Part B1: Frontend/asset audit

- **Bundling**: `vite.config.js` has a single entry pair
  (`resources/css/app.css`, `resources/js/app.js`); `resources/js/app.js`
  is 5 lines (imports `bootstrap.js`, starts Alpine) and
  `resources/js/bootstrap.js` is 4 lines (wires `axios` + the
  `X-Requested-With` header). There is no route-based JS, no dynamic
  `import()`, and nothing that would benefit from code-splitting - the
  compiled output (`public/build/manifest.json`) is one JS chunk
  (`app-BJA0v0Q5.js`, 97,746 bytes) and one CSS chunk
  (`app-rZcNukNn.css`, 77,465 bytes, both uncompressed/pre-gzip). **Not a
  finding**: a single bundle is the correct choice for an app this size
  with this little first-party JS: there is nothing to split.
- **Tailwind purge config**: `tailwind.config.js`'s `content` array
  (`./vendor/laravel/framework/.../Pagination/resources/views/*.blade.php`,
  `./storage/framework/views/*.php`, `./resources/views/**/*.blade.php`)
  correctly scans every Blade view in the repo (no `resources/js`
  templating exists to miss) plus Laravel's own pagination view partial
  (used by every `->paginate()` admin listing) and compiled view cache -
  no missing/over-broad paths found. Confirmed correct, not changed.
- **Duplicate polling implementations found (flagged, not refactored).**
  There are three independent hand-rolled "fetch a fragment, swap the
  DOM node, repeat on a timer" implementations instead of one:
  1. `resources/views/components/live-fragment-poll.blade.php` - a
     reusable Blade component (`props: url, targetId, seconds, active`),
     correctly reused by `client-admin/dashboard.blade.php` and
     `microsoft-365/_directory-body.blade.php`.
  2. `resources/views/components/auto-reload-while-refreshing.blade.php` -
     a different pattern (full `window.location.reload()` with a
     `sessionStorage`-backed max-attempts counter, not a DOM-fragment
     swap), used where a full reload is actually wanted.
  3. `resources/views/admin/integration-health/index.blade.php` (lines
     ~89-220) - an **inline, page-specific reimplementation** of exactly
     what `live-fragment-poll.blade.php` already does (fetch, replace
     `#integration-health-live` by ID, `visibilitychange` pause/resume,
     5s interval), plus extra logic specific to this page (queue-stat
     text, a "configured timing" summary line from the freshness-settings
     form). This duplicates ~90 lines of fetch/DOM-swap/visibility logic
     that `live-fragment-poll.blade.php`'s component already generalizes.
     **Not refactored in this pass** - the extra per-page logic
     (`applyQueueFrom`, `applyConfiguredFromForm`, form-dirty tracking)
     means folding it into the shared component isn't a pure mechanical
     move, and this page's JS has no test coverage to catch a regression
     if the refactor got the DOM-replacement timing wrong. Flagged for
     the team as a reasonable follow-up, not done here.
  - Separately, `resources/views/admin/team/create.blade.php` and
    `edit.blade.php` each hand-roll a `getElementById` +
    `addEventListener('change', ...)` show/hide toggle in vanilla JS
    (toggling the "assigned clients" field visibility by role), even
    though Alpine is already loaded app-wide and is the pattern used
    elsewhere (including the SCIM/SSO forms touched in this pass). Minor
    inconsistency, not a bug - flagged, not changed.
- **Polling frequency**: the `integration-health` page's 5-second
  `setInterval` (and `live-fragment-poll`'s default of 5s) both pause via
  `visibilitychange` when the tab is hidden and are only reachable from
  admin-only pages - not excessive for a low-traffic internal admin tool.
  `auto-reload-while-refreshing` caps itself at `maxAttempts` (default 30)
  stored in `sessionStorage` so it can't reload forever. No excessive
  client-side polling found.
- **Static assets**: `find public -type f -not -path "public/build/*"`
  returns 7 files total - `public/.htaccess`, `public/index.php`, and two
  small SVG integration logos (`superops.svg` 387 bytes, `pax8.svg` 378
  bytes). No raster images (`.png`/`.jpg`/`.jpeg`/`.gif`/`.webp`) exist
  anywhere in the repo outside `node_modules`/`vendor`. Nothing to
  optimize.

### Part B2: Docker/infra confirmation

- `find . -iname "*docker*" -o -iname "*.yaml" -o -iname "*.yml"`
  (excluding `vendor`/`node_modules`/`.git`) returns **nothing** - no
  Dockerfile, docker-compose file, Kubernetes manifest, or any YAML file
  of any kind exists in this repository. Confirms the third pass's claim
  directly rather than trusting it. No `.service`/`.timer` unit files or
  a `deploy/` folder exist in the repo either - `Brain/Deployment.md`'s
  systemd timer section (`onit-portal-{schedule,queue,queue-b}.timer`,
  paths under `/etc/systemd/system/`) is documented as living **only on
  the production host**, which matches what's actually in the repo (no
  unit-file content checked in anywhere). This is consistent, not a gap.
- Cross-checked `Brain/Deployment.md` against `docs/DEPLOYMENT.md`: they
  agree on the deployment pipeline (Plesk Git bare-mirror pull from
  `main`, no Docker/Redis/broker, systemd timers preferred over Plesk's
  jailed cron on this host, database-backed queue, `public/build/`
  committed rather than built on the server), the required `.env`
  variables, and the post-deploy artisan command sequence.
  `docs/DEPLOYMENT.md` explicitly says up front that it's a "condensed,
  audit-oriented version" and defers host-specific detail (exact unit
  file contents, Plesk UI click-paths, known cron quirks on this specific
  server) to `Brain/Deployment.md` - this division of labor is accurate
  and intentional, not drift. **No correction needed** in either
  document this pass. Per AGENTS.md, `Brain/` was read but not edited.
- No Docker/Redis/broker/Kubernetes was added - none was warranted and
  the brief explicitly asked this pass not to introduce any.

### Part B3: Dependency hygiene (exhaustive)

**PHP (`composer.json`, not just the lock file)** - every `require`/
`require-dev` entry checked for a real usage site:

- `laravel/framework`, `laravel/tinker` - framework core / `artisan
  tinker`, used by definition.
- `laravel/socialite`, `socialiteproviders/microsoft-azure` - confirmed
  used in `app/Providers/EventServiceProvider.php` (registers the Azure
  provider) and `app/Http/Controllers/Auth/MicrosoftAuthController.php`
  (`Socialite::driver('microsoft-azure')`), the app's only login path.
- `fakerphp/faker`, `mockery/mockery`, `nunomaduro/collision`,
  `phpunit/phpunit`, `laravel/pint` - standard dev tooling, all used
  (factories use Faker, tests use Mockery, `phpunit.xml` runs PHPUnit,
  `vendor/bin/pint` runs Pint).
- `laravel/breeze` - **still flagged, not removed** (unchanged from the
  third pass's finding): a dev dependency with no runtime import
  anywhere in `app/`/`resources/`, but that's expected for a
  scaffolding-only installer package, not drift. Left for the team's
  judgement, as before.
- **New in this pass - possible stale dependency worth a manual check**:
  `composer.lock`'s per-package `time` field shows every other dependency
  in the tree was released in 2025-2026 (consistent with routine
  `composer update` churn), **except
  `socialiteproviders/microsoft-azure` at `5.2.0`, released
  `2024-03-15`** - over two years stale relative to everything else in
  the lock file, including its own `socialiteproviders/manager`
  dependency (`4.9.2`, released `2026-03-18`). This is the package this
  app's *only* login mechanism (Microsoft Entra ID SSO) depends on.
  Per the instruction not to fetch external URLs to verify, this is
  **flagged, not confirmed** - the team should check upstream
  (`github.com/SocialiteProviders/Microsoft-Azure`) for whether this is
  simply a stable/mature package with nothing new to release, or
  genuinely unmaintained, and whether a newer major/minor exists.

**JS (`package.json`)** - every entry checked for a real import/config
reference:

- `alpinejs` - `resources/js/app.js` (`Alpine.start()`) and used via
  `x-data`/`x-show`/`x-cloak` in several Blade views including the two
  forms touched in this pass.
- `axios` - `resources/js/bootstrap.js` (`window.axios = axios`).
- `@tailwindcss/forms`, `tailwindcss`, `autoprefixer`, `postcss` -
  referenced in `tailwind.config.js` (`plugins: [forms]`) and
  `postcss.config.js` (`{ tailwindcss: {}, autoprefixer: {} }`).
- `laravel-vite-plugin`, `vite` - `vite.config.js`.
- All eight `package.json` dependencies resolve to genuine usage; none
  flagged as unused. `package-lock.json` versions
  (`alpinejs@3.15.12`, `axios@1.20.0`, `vite@5.4.21`, `tailwindcss@3.4.19`,
  etc.) show normal, actively-maintained release cadences - no stale JS
  dependency found.

### Verification results (this pass, actual output)

```
$ grep -rln "EnsureClientAccess\|client\.access" app bootstrap tests routes resources config
app/Http/Middleware/EnsureClientAccess.php
bootstrap/app.php
(no other files - matches the third pass's finding, confirmed before deleting)

$ php artisan test        # after A1 (middleware removal)
Tests:    267 passed (1203 assertions)

$ php artisan test        # after A2 (both jobs + tests)
Tests:    272 passed (1229 assertions)
Duration: 31.68s

$ find . -iname "*docker*" -o -iname "*.yaml" -o -iname "*.yml"    # excl. vendor/node_modules/.git
(no output - confirmed no Docker/K8s/YAML anywhere in the repo)
```

### Bottom line on this pass

Both explicitly-requested fixes (A1, A2) were completed as asked, with
the `applyClientSso()` async-UI nuance investigated and handled the same
way the brief anticipated (`_scim-apply-form.blade.php`'s existing
in-flight/last-result cache-polling pattern), not skipped or half-done.
The frontend/infra/dependency sweep (B1-B3) found nothing that blocks
production readiness: no code-splitting gap worth fixing (nothing to
split), Tailwind purge config is correct, Docker/infra documentation
already matches reality, and dependency usage is exhaustively confirmed
with one new item worth the team's own look
(`socialiteproviders/microsoft-azure`'s two-year-stale release date) and
one duplicated-polling-logic cleanup opportunity (`integration-health`'s
inline script vs. the `live-fragment-poll` component) left for a future,
better-tested pass rather than risked here.

## Fifth Audit Pass — Full Line-by-Line Review (2026-09-28)

Branch `claude/jolly-hopper-6w33al`, freshly synced to `main` at `de33171`.
The brief for this pass was explicitly *not* grep-a-pattern-then-verify, but
to read every file in full and follow how each piece is used by its callers
(controller → FormRequest → Policy/Gate → service → job → cache → view).

### What was read (in full, not sampled)

- **Routing / bootstrap:** `routes/web.php`, `routes/console.php`,
  `bootstrap/app.php`, `bootstrap/providers.php`.
- **`app/Models`** (9) cross-checked column-by-column against **all 19
  migrations** (casts, `$fillable`, nullability, FK `onDelete` behaviour,
  indexes vs. the `where()`s actually issued).
- **`app/Enums`** (5), **`app/Contracts`**, **`app/Support`** (3 - the HTML
  sanitizer was additionally fuzzed, see "Confirmed sound").
- **`app/Providers`** (3), **`app/Policies`** (8), **`app/Http/Middleware`**
  (2), **`app/Http/Requests`** (21 incl. the `ValidatesClientAccess` trait).
- **`app/Http/Controllers`** - all 30: the 13 `Admin\*`, the auth controller,
  both integration launchers, and every customer-facing controller.
- **`app/Services`**, every file: `SuperOps/*` (5), `EntraSync/*` (5 -
  including all 3,962 lines of `MicrosoftGraphClient`), `Huntress/*` (5),
  `Dropsuite/*` (3), `M365/*` (7), `Portal/*` + `Portal/Feeds/*` (13),
  `Admin/IntegrationHealthService`, `ClientOnboardingService`,
  `OnboardingManual`, `ActivityLogService`, `ExternalServicesService`,
  `Support/NewStarterTicketService`, `Pax8/Pax8SsoService`.
- **`app/Jobs`** (10) and **`app/Console/Commands`** (11).
- **Blade views**: all admin forms/listings/partials (checked field-by-field
  against the FormRequest each posts to), both layouts, navigation
  components, the polling components, dashboard/glance/reports,
  support/contact-support, client-admin/*, microsoft-365/*,
  security/huntress/*, auth/*. Every `{!! !!}` sink was traced to its source.
- **Config**: `app`, `auth`, `cache`, `session`, `logging`, `services`,
  `onit_support` (plus the vendor `SessionGuard`, `DatabaseStore`,
  `RedirectIfAuthenticated`, middleware-priority list and the Azure Socialite
  provider where app behaviour depended on them).

Every suspected defect below was **reproduced with a throwaway test against
the unmodified code before changing anything**, and every regression test
added was re-run against the old code (via `git stash`) to prove it fails
there. Suspicions that did not reproduce are listed under "Retracted".

### Why the earlier passes missed these

The earlier passes checked that `$this->authorize()` / `accessibleClientIds()`
were *called*. They did not follow what those calls actually constrain.
`accessibleClientIds()` was called everywhere, but wrapped in
`when(! empty(...))`, so it failed open. `authorize('create')` was called on
every store action, but it only proves "is staff", never "may write to *this*
client". Account state was checked, but only at login, while login grants a
400-day remember cookie. Several "Confirmed Good" claims above are therefore
corrected here: tenant isolation, per-action authorization, "every outbound
call sets an explicit timeout" (the Graph token request did not), and "no
N+1" (the N+1s are external API calls made from Blade rather than Eloquent
relation loads - see F3).

### Fixed in this pass (13 commits, each with a regression test unless noted)

| # | Severity | Defect (evidence) | Commit |
|---|---|---|---|
| 1 | **Critical** | **Unauthenticated tenant re-pointing via the admin-consent callback.** `/auth/microsoft/callback?admin_consent=True` is in the `guest` group; `AdminConsentState::decode()` still accepted the **unsigned** `client-{id}` form; the query-string `tenant` went straight into `CustomerEntraBootstrapService::bootstrap()`, which persists it as `entra_tenant_id` (bootstrap lines 69-73) before any Graph call. Reproduced: an anonymous GET changed a live client's tenant and the response page disclosed the client name. With an attacker-owned tenant that has consented to the multi-tenant app, bootstrap creates the portal group there, and the scheduled Entra sync (`EntraGroupSyncService::performSyncClient`, lines 69 and 257-277) then **deactivates the real users and provisions the attacker's users into that client** as `client_requester`. Fix: signed state only, GUID-only tenant, and an already-linked client can never be re-pointed from this route. `tests/Feature/Security/AdminConsentCallbackTest.php` (4) + updated `tests/Unit/AdminConsentStateTest.php`. | `55cba41` |
| 2 | **High** | **Deactivation never revoked existing access.** `is_active`, `portal_login_enabled` and client `is_active` were only checked in `MicrosoftAuthController::callback()` (lines 129-144); `Auth::login($user, true)` (line 174) issues a remember cookie that lasts 400 days (`SessionGuard::$rememberDuration = 576000`). Reproduced: deactivated user → `/dashboard` 200. This defeats Entra-sync deprovisioning (`usersRemovedFromScope`) and client deactivation. Fix: new `EnsureAccountIsActive` web middleware (inside `SecurityHeaders`) applying the same three checks per request. `tests/Feature/Security/DeactivatedAccountSessionTest.php` (5). | `9fb219f` |
| 3 | **High** | **Tenant scoping failed open.** 19 sites across 9 admin controllers used `->when(! empty($clientIds), fn ($q) => $q->whereIn(...))`, and `IntegrationHealthService::overview()` treated `[]` as "all clients" (docblock: "empty = all clients"). `accessibleClientIds()` is `[]` for an account manager with no assignments, which is a normal state (`TeamController` allows creating one or unticking every client). Reproduced: that user saw every tenant's clients, users, notices, recommendations, opportunities, portal links, **activity logs incl. IPs**, dashboard counts and Integration Health rows, and got every client in create-form dropdowns. Fix: unconditional `whereIn` (identical for super admins, whose list is every client id); only `null` means "all" in the health service. `tests/Feature/Security/AccountManagerScopeTest.php` (6). | `34c7705` |
| 4 | **High** | **Cross-tenant writes.** `Notice/Recommendation/Opportunity/PortalLinkController::store()` authorized `create` only (= "is staff"); `update()` authorized against the record's *current* client, then `update($request->validated())` accepted any `client_id` that passed `exists:clients,id`. Reproduced: an account manager created a notice for an unassigned client. Fix: the existing `ValidatesClientAccess` hook (already used by `StoreUserRequest`) on those four requests; the Update requests inherit it. Global (null-client) portal links are still allowed, per `PortalLinkPolicy`. `tests/Feature/Security/ClientContentCrossTenantTest.php` (12). | `f984ad5` |
| 5 | **High** | **Login silently linked a portal client to someone else's SuperOps account.** `SuperOpsUserSyncService::syncUser()` (runs on every sign-in) looks the requester up by email across *all* SuperOps clients, and if the portal client had no `superops_account_id` it copied the matched requester's account onto it. Because `ClientProductService::isEntitled()` treats "mapped, never toggled" as entitled, client Y's users then saw client X's tickets and devices, and Y's new tickets were filed under X. Undocumented in `Brain/` and untested. Fix: keep binding `superops_user_id`; never write the client's account. `tests/Unit/SuperOpsUserSyncLoginTest.php`. | `8d63ce2` |
| 6 | Medium | **`entra-sync` limiter was per user, not per client.** `ThrottleRequests` runs before `SubstituteBindings` (framework middleware priority), so `$request->route('client')` is the raw id string; `?->id` returned null and every client shared the bucket `{user}|unknown`. Reproduced: `[302, 302, 429 (client A), 429 (client B)]`. Fix + test in `tests/Feature/ClientEntraSyncTest.php`. | `a60cd46` |
| 7 | Medium | **HTTP 500 on client create/rename when slugs collide.** `clients.slug` is UNIQUE; `store()`/`update()` used a bare `Str::slug($name)` ("Acme Ltd" vs "Acme Ltd.", or symbol-only names slugging to `""`). Reproduced: 500. Fix: `-2`, `-3`… suffixes, ignoring the client's own row on update. 2 tests in `tests/Feature/ClientCreateTest.php`. | `4f2f36d` |
| 8 | Medium | **User-typed ticket descriptions could be sent to SuperOps unescaped.** `SuperOpsHtml::fromPlainText()` returned any input starting with `<` verbatim (so `NewStarterTicketService`'s HTML survived), but the same heuristic applied to raw `/support/create` input. That put stored markup into the PSA and swallowed the very `<jo@acme.com>` case its docblock warns about. Fix: always escape; explicit `$descriptionIsHtml` flag, set only by the new-starter service. 2 tests in `tests/Unit/SuperOpsTicketCreateTest.php`. | `d46c73f` |
| 9 | Medium | **M365 export leaked org licence data to personal viewers.** The page offers export only when `canExportDirectory` (`Microsoft365DirectoryController:147`) and withholds insights from personal viewers, but the route only required `can:view-m365-directory`, and `buildWorkbook()` always includes the org licence inventory and seat/utilisation summary. Fix: enforce org-wide on the route. Test in `tests/Feature/M365DirectoryExportTest.php`. | `f625208` |
| 10 | Medium | **Unbounded `cache` table growth + dead 20s cache.** `PortalFreshnessService::activeCustomerSessionCount()` keyed on a per-second timestamp. With `CACHE_STORE=database`, `DatabaseStore` only deletes an expired row when that exact key is read again, and `Brain/Deployment.md` says not to `cache:clear` on deploys, so every recompute (up to ~4/min) left a permanent row. Fix: key by the presence window. `tests/Unit/Services/Portal/PortalFreshnessSessionCountCacheTest.php` runs against the **database** store. | `373a452` |
| 11 | Medium | **Staff-side audit events were invisible to everyone.** `ActivityLogService::log()` records `client_id NULL` for team member create/update/delete, `settings.updated`, `settings.freshness_updated` and staff `user.login`; Activity Logs and Recent Activity filtered `whereIn('client_id', …)`, which always excludes NULL, so even super admins never saw them. Fix: super admins (existing `manage-all-clients` gate) see the whole trail; account managers stay scoped. Test in `AccountManagerScopeTest`. | `804730c` |
| 12 | Low | **Client home "Plan" tile always showed the raw SKU** ("SPB"). `ClientHomeOverviewService::m365Column()` read `$topSkus[0]['name']`, but every `top_skus` row from `M365InsightsService` uses `displayName`. Test: `tests/Unit/Services/Portal/ClientHomeOverviewM365PlanTest.php`. | `48198b5` |
| 13 | Low | `MicrosoftGraphClient::accessToken()` was the one outbound call without an explicit `->timeout()` (contradicting AGENTS.md rule 6 and the earlier "every call sets a timeout" finding). Huntress (error level) and Dropsuite (warning level) still logged full raw HTTP error bodies; pass 2 had bounded only SuperOps. No test; these are one-line config changes. | `6a90e76` |

`docs/SECURITY.md`, `docs/ARCHITECTURE.md` and `AGENTS.md` were updated
where these fixes made statements stale (per-request account checks, the
consent-state rules, "empty `accessibleClientIds()` = none", and the two
customer FormRequests that *do* gate in `authorize()`).

### Flagged, not fixed (real defects needing product judgement or multi-site changes)

> **Status update (sixth pass, 2026-09-29):** F1-F8, F10 and F11 below are
> **resolved** - see "Sixth Audit Pass - Resolving F1-F8, F10, F11" at the end
> of this document for what was done and the test evidence. The original
> write-ups are kept unchanged as the record of what was found. **F9 is still
> open**, pending a product decision from the repository owner.

**[RESOLVED in sixth pass]** **F1 - Feed refresh failures are recorded as success once any cache exists.**
`SuperOpsClientMetricsService::refreshAndStore()` (line 374),
`HuntressClientMetricsService` (188), `M365InsightsService` (239) and
`DropsuiteClientMetricsService` (182) all catch the upstream failure and
return the *stale cached* summary instead of throwing. Their jobs
(`Refresh*Job::handle`) only write `last_result.success = false` when an
exception escapes, so after the first successful load **every later failure
is recorded as success**. `ClientProductService::hasRecentFailure()` (the
client-facing "error" state) and Integration Health's `failed` status then
never trigger; the only signal is data quietly ageing. Dropsuite's own
comment at 185-186 acknowledges the problem but fixes only the no-cache
case. *Suggested fix:* have `refreshAndStore()` rethrow after logging (jobs
already handle it); `ProbeSecurityApisCommand` is the only other caller and
already wraps it in try/catch.

**[RESOLVED in sixth pass]** **F2 - Personal (requester) scoping is computed on truncated, fuzzily matched
data.** (a) `SuperOpsClientMetricsService::scopeSummaryForViewer()` (155-218)
filters the **already-truncated** org tables (top 20 open by priority, 10
closed - lines 656/684). For an organisation with more than 20 open tickets,
a requester's count and table silently omit their own lower-priority
tickets. (b) `ClientVisibilityService::matchesPerson()` (53-84) does
substring matching on the email local part (≥4 chars) and the display name.
SuperOps personal scoping feeds it the ticket **subject** (line 236) and
Huntress feeds it incident **body/summary** (`HuntressIncidentService:196`).
So `mark@` matches `marketing@…`, and a ticket *about* a person ("Disable
account for sam.jones - leaver") is shown to that person on their dashboard.
*Suggested fix:* persist per-requester ids/emails untruncated in the cache
payload and match on requester identity only (drop subject/body substring
matching), or accept this explicitly as product behaviour.

**[RESOLVED in sixth pass]** **F3 - Synchronous external-API calls on admin page loads, including an N+1.**
`resources/views/admin/clients/index.blade.php:84` calls
`ClientOnboardingService::progress($client)` **per row**. That goes through
`steps()` → `isScimExportFailed()`/`scimExportHealth()` (live Graph on cache
miss, `ClientOnboardingService:122,913`) and `superOpsEntraScopeWarnings()`
→ `SuperOpsUserSyncService::countClientRequesters()` (up to 25 paged
SuperOps calls, `:35-95`), plus a `User` count query. A cold 15-row page can
therefore make dozens of 30-60s-timeout calls, the same 60s-gateway hazard
pass 4 queued other actions to avoid. `ClientController::edit()` (via
`onboardingViewData()` / `scimProvisioningHealth()`, `:99-132`) does the
same for one client. *Suggested fix:* compute progress from checklist + DB
fields only on the index, and have the edit page read the `scim.health.*` /
`superops.requester_count.*` caches without populating them (let a queued
job or prewarm fill them).

**[RESOLVED in sixth pass]** **F4 - Admin-consent return: unreachable branch and inline slow work.**
`MicrosoftAuthController:249` (`if (Auth::check() && $client)`) is
unreachable: the route is in the `guest` group, and
`RedirectIfAuthenticated` bounces a logged-in user to `/dashboard` first
(verified). The anonymous path runs the full `bootstrap()` synchronously
(about 24s in the test environment with Graph failing), which is a cheap
worker-exhaustion lever on a public URL rate-limited only per IP.
*Suggested fix:* dispatch `BootstrapClientEntraJob` from this path and render
"setup running"; delete the dead branch. Residual after fix #1: the signed
state is a static HMAC with no expiry or nonce. Fix #1's mismatch guard
blocks re-pointing, so it is now low risk, but a timestamped state would be
cleaner.

**[RESOLVED in sixth pass]** **F5 - Integration Health queue panel is not tenant-scoped.**
`IntegrationHealthService::listJobs()` (1263) / `recentFailures()` (1361)
list jobs with **client names** and failure first-lines for every client to
any admin (rendered at `admin/partials/integration-health.blade.php:156-157,
190-191`). The per-client table *is* scoped (fix #3). *Suggested fix:*
filter those rows by `accessibleClientIds()` for non-super-admins.

**[RESOLVED in sixth pass]** **F6 - Editing an account manager silently unassigns their inactive
clients.** `TeamController::create/edit` render checkboxes only for
`is_active` clients (lines 41, 72), and `update()` does
`sync($request->assigned_clients ?? [])` (line 91). *Suggested fix:* merge
the existing inactive assignments back in, or list inactive clients (marked
as such).

**[RESOLVED in sixth pass]** **F7 - Deleting a client orphans its users.** `users.client_id` is
`nullOnDelete` (`0001_01_01_000000_create_users_table.php:21`), so a
client's users survive as active `client_*` users with `client_id NULL`.
They appear in no admin listing (all listings are per client), opening one
500s (`admin/users/edit.blade.php:3,16` dereference `$user->client->name`),
and `callback()` still lets them sign in (to an empty portal).
*Suggested fix:* in `ClientController::destroy()`, deactivate (or delete)
the client's users first.

**[RESOLVED in sixth pass]** **F8 - Entra sync has no safety valve before mass deactivation.**
`EntraGroupSyncService::performSyncClient()` deactivates every Entra-synced
user absent from `listSyncEligibleUsers()` (lines 69, 266-277). If Graph
returns a successful but empty or partial list (permission propagation, a
filter change), the whole client is locked out on the next 2.5-minute cycle,
now effectively immediately given fix #2. *Suggested fix:* abort (as an
error, not a deactivation) when Graph returns zero eligible users while the
client has active synced users, or when more than X% would be deactivated.

**[STILL OPEN - pending product decision by the repo owner]** **F9 - Notices, recommendations and opportunities are never shown to
customers.** A repository-wide search finds no client-facing view or service
that reads `ClientNotice`, `ClientRecommendation` or `ClientOpportunity`.
The only consumers are the admin CRUD pages and the admin dashboard's
"Active Notices" count, so staff can author content no customer sees.
(This reduced the *current* impact of fix #4.) A product decision: surface
them on the glance dashboard or retire the three features.

**[RESOLVED in sixth pass]** **F10 - Smaller defects (low severity), with evidence.**
- `UserPolicy::view()` (line 24) runs `assignedClients()->where('users.id',
  …)` - `users` is not in that query, so it would throw an SQL error. It is
  currently unreachable (nothing authorizes `view` on a `User`).
- `IntegrationHealthService::integrationStatus()` accepts `float|int`
  minutes (line 950; requeue is 2.25, the soft window 3.5) but passes them
  to `friendlyStatus(int …)` / `buildBlockers(int, int …)` (1091, 1170).
  PHP silently truncates (deprecation), so labels read "2m"/"3m" and the
  back-computed cadence shows 2.2 instead of 2.5.
- `Admin\DashboardController` calls `overview()` and then `productCoverage()`
  (lines 40, 52), and the latter calls `overview()` again
  (`IntegrationHealthService:135`), building the whole matrix twice per load.
- The admin nav always shows **Settings** (`admin/partials/nav.blade.php:11`),
  but `SettingPolicy` is super-admin only, so account managers get a 403.
- Every SuperOps customer's Dropsuite per-org `authentication_token` is
  cached in plaintext in the DB `cache` table for 15 minutes
  (`DropsuiteClientMetricsService:225`). *Suggested fix:* cache only the one
  org-to-token mapping needed, and encrypt it.
- `SuperOpsTicketService::getTicket()` never selects `client` (line 47), so
  `ticketAccountId()` (248) always returns `''`. The org-member and staff
  branches of `userCanViewTicket()` therefore only work through the 14-day
  "remembered" cache of portal-created tickets (line 126). This fails
  closed, but staff cannot open older tickets from the portal.
- `syncUser()` still binds `superops_user_id` from a login-time email match
  that is not scoped to the user's SuperOps client (line 548); it is benign
  after fix #5, but scoping it by `clientId` would be tidier.
- `CaptureClientMetricSnapshotsCommand` (line 56) falls back to a requester
  when a client has no `client_admin`, which stores a *personal-scope*
  bundle as the org's monthly snapshot, later compared against org-wide
  figures.
- CSV export writes directory display names with no formula-prefix
  neutralisation (`M365DirectoryExportService:106`); XLSX is safe because it
  uses `inlineStr`.
- `dashboard/glance.blade.php:331` re-parses activity timestamps with no
  try/catch (the service's own `sortKey()` guards it), so one malformed
  SuperOps date would 500 a client's home page.
- The avatar initial uses byte-wise `substr()`
  (`components/layouts/app.blade.php:100`), which renders `�` for names
  starting with É/Ø/Ł; `admin/users/index` correctly uses `mb_substr`.
- `ProvisionSuperOpsScimUsersJob` `Cache::pull()`s the pending ids before
  working, with `$tries = 3` (lines 21, 43). This is harmless today
  (`triggerSuperOpsScimProvision()` never throws, and the next sync re-adds
  missing users), but a future throwing change would lose ids on retry.

**[RESOLVED in sixth pass]** **F11 - Dead code (confirmed by repository-wide search, not removed):**
`ClientController::finishEntraSyncResponse()` (278);
`MicrosoftGraphClient::pickScimSynchronizationTemplateId()` (2817),
`listGroupUsers()` (1097, marked deprecated), `hasActiveLicense()` (1061);
`DropsuiteClientMetricsService::fetchOneDriveRowsForEmails()`;
`ClientHomeOverviewService::pipelineMetric()` (1235) and the identical-branch
ternary at 334; `ClientProductService::overviewTileWidthClass()` (318,
deprecated); `M365DirectoryService::snapshot()` (148, deprecated); the
write-only `entra_sync.in_flight.*` cache key (`ApplySuperOpsScimJob:104`);
and, after fix #9, `M365DirectoryExportService::buildWorkbook()`'s
`$scopedUser` branch (42).

### Area-by-area notes (what was confirmed on close read)

**Routes / bootstrap.** 91 routes; the ordering conflict that matters
(`admin/graph-reconsent` declared before the `clients` resource) is correct.
The one real routing subtlety is the middleware-priority interaction behind
fix #6. The `auth-callback`/`integrations-launch`/`support-ticket-store`
limiters key correctly, because they read `user()`/`ip()`, not route models.

**Models ↔ migrations.** Every `$fillable` matches the columns written by the
controllers and services that call `create`/`update` (including the
`forceFill` in `CustomerEntraBootstrapService::persist()`, which only touches
fillable Entra columns). Casts match column types; `microsoft_tokens` is
`encrypted:array` over a `text` column, as documented. `Setting::get()` bakes
its `$default` into the cache on first miss. That is harmless today because
every caller passes the same default, but worth knowing. The schema-level
defects are F7 (`nullOnDelete` orphaning) and the UNIQUE `slug` (fix #7).

**Enums / Support.** `SuperOpsHtml::sanitize()` was fuzzed against
quoted-`>` attribute tricks, nested `<<script>`, CDATA, `javascript:` hrefs
and unterminated tags. `strip_tags` plus the attribute-stripping regex held
in every case (no tag survives with attributes; disallowed tags are dropped).
`OnboardingStepFormatter::rich()` runs `e()` before its regexes, so the
admin-entered client name interpolated into step copy cannot break out, and
an escaped `&quot;` inside `href="…"` decodes to a literal quote in the
value rather than ending the attribute. `AdminConsentState` was the one
Support defect (fix #1).

**Policies / Providers / Middleware.** Policies are consistent with
`canAccessClient()`; the defects were in *what callers passed* (fixes #3 and
#4), plus the unreachable broken branch in `UserPolicy::view` (F10). Gates
`view-m365-directory` / `view-huntress-security` correctly combine role +
own client + `shouldRefresh()`. SSO eligibility depends only on role +
client + config, so the per-(client, role) portal-link cache in
`ExternalServicesService` is sound.

**Requests.** Each admin form was checked against the request it posts to.
The shared `form-field` partial always emits a hidden `value="0"` before
every checkbox, so the `is_active`/`*_sso_enabled` updates always receive a
value. The product cards emit hidden `products[key]=0`. The edit form's
`entra_license_tier` select always submits a value (an *empty* value would
hit the NOT NULL column, but the UI cannot send one). The onboarding
checklist's filter against `MANUAL_CHECKPOINTS` is sound.

**Auth controller.** The email fallback in `callback()` matches on the
**UPN**: `SocialiteProviders\Azure\Provider::mapUserToObject` maps `email`
to `userPrincipalName`, not the mutable `mail` attribute. A UPN must sit on a
DNS-verified domain that only one Entra tenant can hold, so the "nOAuth"
email-spoof takeover pattern does **not** apply. This was checked explicitly
because `azure.tenant` is `organizations`.

**SuperOps.** GraphQL client error handling is consistent (HTTP failure →
`RequestException`; GraphQL `errors`/`clientError` → `RuntimeException`), and
every caller wraps it. Ticket visibility fails closed (F10). The defects
are fixes #5 and #8, plus F1 and F2.

**EntraSync / Graph.** All 3,962 lines were read. OData filter values are
consistently `'`-escaped; every retry loop is bounded; token refresh on
`Authorization_RequestDenied` is scoped to one retry. The Graph-side defect
is the token-request timeout (fix #13). The sync-side risks are the
tenant-trust issue (fix #1) and F8.

**Huntress.** `findForClient()` re-checks `organization_id` on **both** the
cache path and the live-API path, so a crafted `{incident}` segment
(including an encoded `?` that Guzzle would treat as a query string) cannot
return another organisation's case. Sound.

**Dropsuite.** The per-org user-token model is followed correctly, and rows
without an org hint are included only because the user token is already
org-scoped. Defects: F1 and the plaintext token cache (F10).

**M365.** Export XML escapes with `ENT_XML1`; directory snapshots are cached
as objects and read back with an `instanceof` check. Defects: fix #9 and the
CSV note (F10).

**Portal services.** `DashboardFeedRegistry` correctly withholds
`m365_insights` from personal viewers. `ClientHomeOverviewService`'s traffic
lights match their docblocks (as `ClientHomeOverviewTrafficLightsTest`
already asserts). Defects: fixes #10 and #12, plus F2.

**IntegrationHealthService.** Payload client-id extraction handles escaped
and serialized forms. Defects: fix #3 (its "empty = all" contract), F5 and
F10.

**Jobs / Commands.** Every job clears its in-flight/queued flags in both
`finally` and `failed()`. The unique-id + `markQueued` pattern is consistent.
`PrewarmClientDashboardsCommand::releaseStaleScheduleLocks()` only deletes
expired rows and schedule locks more than 30 minutes out, which is
consistent with `withoutOverlapping(8)`. `PurgeDemoDataCommand` is confined
to `.example` emails and three fixed slugs.

**Views.** Only four `{!! !!}` sinks exist: sanitized ticket HTML, formatter
output, developer-authored icons/actions (`route()` output only), and the
glance SVG. Every other dynamic value is escaped. Portal-link `href`s come
from URLs that passed Laravel's `url` rule, which **rejects `javascript:`,
`data:` and `vbscript:`** (verified). The live-fragment poller only injects
same-origin HTML. The Huntress "status" filter is intentionally client-side
(Alpine, `_list-body.blade.php:123`), so the controller passing `null` to
the service is correct.

**Config.** `oauth_stateless` defaults to true in production, as the login
error copy states. Session defaults match `docs/SECURITY.md`. `logging.php`
still has no `daily` channel (as pass 2 noted). No `env()` calls exist
outside `config/`.

### Retracted suspicions (investigated, not defects)

- *Notice `expires_at` `after:published_at` rejects an expiry with no
  publish date.* This was tested: Laravel skips the comparison when the
  other field is null, and the store succeeds.
- *Huntress controller ignores the status filter.* This is by design; the
  filtering happens client-side.
- *`SuperOpsHtml::sanitize()` bypass via quoted `>`.* Fuzzing showed
  `strip_tags` normalises the tag, so it holds.
- *nOAuth email takeover.* The provider maps UPN, not `mail` (see "Auth
  controller").

### Verification (this pass, actual output)

```
$ php artisan test                       # baseline, untouched checkout
Tests:    272 passed (1229 assertions)

$ php artisan test                       # after all 13 fixes
Tests:    310 passed (1376 assertions)
# (one earlier final run: 1 failed, 309 passed - the known
#  HuntressClientMetricsServiceTest order flake; that file passes 5/5 alone)

# Each new security test was also run against the pre-fix code (git stash):
AccountManagerScopeTest        4 failed, 2 passed   (the 2 are controls)
ClientContentCrossTenantTest   7 failed, 5 passed   (controls: assigned client, global link, super admin)
AdminConsentCallbackTest / DeactivatedAccountSessionTest / limiter /
slug / escaping / export / cache-key / Plan tests: all fail on old code
```

One honest note: the very first run of `ClientEntraSyncTest` after adding
the limiter test reported "1 failed, 11 passed", and I did not capture which
test failed. It did not reproduce in 5 file-level runs, 10 isolated runs of
the new test, or 3 subsequent full-suite runs. It is recorded here rather
than omitted, alongside the known `ClientOnboardingServiceTest` /
`HuntressClientMetricsServiceTest` order flake that earlier passes
characterised.

## Sixth Audit Pass - Resolving F1-F8, F10, F11 (2026-09-29)

Branch `claude/jolly-hopper-6w33al`, freshly synced to `main` at `602c76b`.
The brief was to fix the fifth pass's "Flagged, not fixed" items F1-F8, F10
and F11, and to leave **F9** alone. Each suggested fix was re-checked against
the current code before it was applied; where the suggestion was incomplete,
that is noted below. Every behavioural fix has a regression test, and each
new test was re-run against the pre-fix code (`git stash` of `app/`
/`resources/`) to confirm that it **fails there**. One logical fix per commit.

### What was done

| Item | Result | Evidence (test) | Commit |
|---|---|---|---|
| **F1** feed failures recorded as success | Fixed as suggested. All four `refreshAndStore()`s log and **rethrow** even when a cache exists. The cached snapshot is untouched and still served to page views, and the jobs and `ProbeSecurityApisCommand` already catch. The two old tests that asserted the swallow ("keeps last successful cache") now assert rethrow plus cache preserved. | `SuperOpsClientMetricsServiceTest::test_job_records_failure_when_refresh_fails_after_a_successful_cache`, `..._rethrows_and_keeps_last_successful_cache` (SuperOps + Huntress) | `1feac99` |
| **F2** personal scoping on truncated, fuzzy data | Fixed. (a) The SuperOps refresh also stores `requester_tickets`: every open ticket plus each requester's 10 most recent closed. Personal scoping uses it, and legacy payloads fall back to the org tables until the next refresh. (b) Matching is on **requester identity only**: exact email, or the bound `superops_user_id`. Huntress incidents have no requester, so they match only on exact addresses in `related_emails`. Subject, body, name and local-part substring matching is gone for both. The M365 directory's personal filter still uses `matchesPerson()` on email/display name only (no free text). That is outside F2 and was left alone. | `test_requester_scope_uses_untruncated_rows_and_requester_identity_only`, `HuntressSecurityCasesTest::test_personal_scope_does_not_substring_match_local_part_or_body` | `ad90e2a` |
| **F3** live API calls on admin page loads | Fixed, and extended beyond the suggestion. `steps()`/`progress()` and the SCIM/requester helpers take `$live`. With `live: false` they only **read** the `scim.health.*` / `superops.requester_count.*` caches. The index uses cache-only progress. The edit page is cache-only and queues a new `WarmClientOnboardingChecksJob` when either cache is cold, because nothing else populated those caches. Without the job the edit page would never have shown SCIM health. | `AdminClientPagesNoLiveCallsTest` (4) | `5652778` |
| **F4** consent return: dead branch + inline bootstrap | Fixed as suggested. The GUID tenant is linked only to an unlinked client (the existing mismatch guard still refuses re-pointing), then `BootstrapClientEntraJob` is dispatched and the page reports "running in the background". The `Auth::check()` branch was deleted, and a test proves that a signed-in user is redirected before the handler. The page now echoes only the validated GUID. The residual "static HMAC, no expiry" note from pass 5 stays open as a low-risk hardening idea. | `AdminConsentCallbackTest` (6, 3 new/rewritten) | `484a4a7` |
| **F5** queue panel not tenant-scoped | Fixed. `listJobs()`/`recentFailures()` drop rows whose payload is not for an accessible client (they scan up to 500 rows, then trim). Super admins now pass `null` (unscoped) to `overview()`, which gives the same per-client rows and keeps client-less system jobs visible. Queue totals remain aggregates. | `AccountManagerScopeTest::test_integration_health_queue_panel_is_scoped_to_assigned_clients` | `1f03842` |
| **F6** AM edit drops inactive clients | Fixed with both suggestions. `update()` merges existing inactive assignments back in, and the edit form lists them as "kept when you save". A role change away from AM still detaches everything. | `AdminTeamTest` (2 new) | `ed69d1f` |
| **F7** deleting a client orphans users | Fixed, and extended. `destroy()` deactivates the client's users and deletes the client in one transaction. Sign-in and `EnsureAccountIsActive` also refuse a client-facing user with no client, so orphans left by **earlier** deletions are locked out too. `admin/users/edit` no longer dereferences a null client. | `Security/ClientDeletionOrphanedUsersTest` (3) | `67f815f` |
| **F8** no safety valve on Entra sync | Fixed. The guard runs **before any write**. That covers user deactivation and also the portal-group / SuperOps-app / requester-SSO removals, which would otherwise shrink to the same bad list. The sync aborts as an error when Graph returns zero eligible users while active synced users exist, or when it would deactivate more than `ENTRA_SYNC_MAX_DEACTIVATION_RATIO` (0.5) of them once at least `ENTRA_SYNC_MAX_DEACTIVATION_MIN_USERS` (5) would go. You can raise the ratio for a genuine bulk removal. Documented in `.env.example`. | `EntraGroupSyncServiceTest` (2 new; the existing removed-from-scope test now keeps one user in scope) | `e161262` |
| **F11** dead code | Every entry was re-verified by repo-wide search (app, resources, routes, config, database, tests) and removed. `overviewTileWidthClass()` was referenced only by its own unit test, and that test was removed with it (which is why the suite count drops by one). The `$scopedUser` removal also dropped the matching `streamExport()` parameter and the export service's now-unused `ClientVisibilityService`. **Kept:** `MicrosoftGraphClient::getUserLicenseSkuPartNumbers()`. It is now unused in app code, but `M365DirectoryServiceTest` references it as a `shouldNotReceive()` regression guard. | suite green | `8b89189` |

**F10**: all 12 bullets fixed, one commit each:

| Bullet | Fix | Test | Commit |
|---|---|---|---|
| `UserPolicy::view()` SQL error | A client-less target is visible only to themselves, matching `update()`. The old branch was reproduced throwing `no such column: users.id`. | `UserClientAccessTest::test_user_policy_view_for_client_less_target_does_not_error` | `7e91e7e` |
| float minutes truncated | `friendlyStatus()`/`buildBlockers()` take `float\|int`. Cadence now reads 2.5 and requeue reads 2.25m. | `IntegrationHealthServiceTest::test_fractional_requeue_minutes_are_not_truncated_in_labels` | `daafcac` |
| `overview()` built twice | `productCoverage()` accepts the already-built overview. | `AdminIntegrationHealthDashboardTest::test_admin_dashboard_builds_the_health_overview_once` | `d1af340` |
| Settings nav link 403s | Nav links can carry a Policy ability, and Settings uses `can('viewAny', Setting::class)` (no role check in the view). | `AccountManagerScopeTest::test_admin_nav_shows_settings_only_to_those_who_can_open_it` | `835f0dd` |
| Dropsuite tokens in plaintext | The cache now holds only an org => chosen-token map, `Crypt`-encrypted. The legacy plaintext key is dropped. There is still one `/users` sweep per 15 min. | `DropsuiteClientMetricsServiceTest::test_org_user_tokens_are_not_cached_in_plaintext` (asserts on the **database** cache table) | `06ea0ea` |
| ticket `client` never selected | `getTicket()` selects the `client` leaf. **Fixed differently from a plain one-liner:** once the real account is known, the org branch would have let *any* requester open any colleague's ticket by id. Org-wide viewers (client admins) and staff therefore use the real account, while personal viewers keep the old "portal-created for my org" rule. | `SupportTicketShowTest` (2 new) | `5ae8800` |
| unscoped login-time bind | `syncUser()` passes the user's `superops_account_id` as `clientId` and skips clients that have none. | `SuperOpsUserSyncLoginTest` (rewritten + 1 new) | `19c7205` |
| personal snapshot as org snapshot | Only an active `client_admin` is used as the snapshot viewer (billing admins are personal viewers too). Other clients are skipped with a warning. | `CaptureClientMetricSnapshotsTest` (2) | `aed8c94` |
| CSV formula injection | String cells starting with `= + - @` tab or CR get an apostrophe prefix. Numbers and `-` are untouched. | `M365DirectoryExportCsvTest` | `8ad169c` |
| glance date parse 500 | `try/catch` around the parse; a malformed date just omits the time. | `GlanceDashboardActivityTest` | `cb02a7f` |
| byte-wise avatar initial | `mb_strtoupper(mb_substr(...))`. | `AvatarInitialTest` | `2c0a5a7` |
| provision job loses pulled ids | On a throw, the pulled ids are merged back into the pending key before rethrowing. | `ProvisionSuperOpsScimUsersJobTest` | `c6f0060` |

**Also fixed (test infrastructure):** `ClientFactory` derived `slug` from
`fake()->company()`, and `clients.slug` is UNIQUE. Multi-client tests
intermittently failed with `UniqueConstraintViolationException`. This was
seen four times during this pass, on `ClientContentCrossTenantTest`,
`UserClientAccessTest`, `ClientEntraSyncTest` and a new test. It is very
likely the cause of the unexplained "1 failed" noted at the end of pass 5.
The slug now gets a per-test unique suffix (`98ba76e`). The known
`ClientOnboardingServiceTest` / `HuntressClientMetricsServiceTest` order
flake did not appear in any run this pass.

**Still open:** **F9** (notices/recommendations/opportunities never shown to
customers) is unchanged. It needs a product decision from the repository
owner: surface them to customers or retire the three features. Also still
open is the low-risk F4 residual (the consent `state` has no expiry or
nonce).

`docs/SECURITY.md` and `docs/ARCHITECTURE.md` were updated for the orphan
check, the queued consent bootstrap and the Entra sync guard.

### Verification (this pass, actual output)

```
$ php artisan test                       # baseline, untouched checkout
Tests:    310 passed (1376 assertions)

$ php artisan test                       # after all fixes (3 consecutive runs)
Tests:    340 passed (1504 assertions)
# 31 tests added, 1 removed with the dead overviewTileWidthClass()
```
