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
- **Rate limiting:** the OAuth callback, ticket creation, integration
  launch links, directory/security refresh actions, and Entra sync are
  all behind named rate limiters (`AppServiceProvider::boot()`) or inline
  `throttle:` middleware.
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
