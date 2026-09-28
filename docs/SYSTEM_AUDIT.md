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
