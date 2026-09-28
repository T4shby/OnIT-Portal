# AGENTS.md - On IT Portal

Guidance for any human or AI agent working in this repository. Read this
before making changes, especially around authorization, secrets, and
deployment.

## What this is

A Laravel 11 monolith ("On IT Portal") that is the branded customer/staff
portal for an MSP (managed service provider). It aggregates data from
several external platforms (SuperOps PSA, Microsoft 365 / Entra ID, Huntress
security, Dropsuite backup, Pax8) behind a single login and presents it
per-client with strict multi-tenant isolation.

## Tech stack

- **Backend:** PHP 8.2+, Laravel 11 (Blade views, no API/SPA split)
- **Frontend:** Blade + Tailwind CSS 3 + Alpine.js, bundled with Vite 5
- **Auth:** Microsoft Entra ID via Laravel Socialite (`socialiteproviders/microsoft-azure`)
  - single sign-on only; there is no local password login
- **DB:** MySQL/MariaDB in production, SQLite in-memory for tests
- **Session/cache/queue:** database-backed (no Redis) - `SESSION_DRIVER=database`,
  `CACHE_STORE=database`, `QUEUE_CONNECTION=database`
- **Jobs:** `php artisan queue:work` via systemd timers on the production host
  (see `Brain/Deployment.md`); no Horizon, no Redis, no message broker
- **Hosting:** Plesk on Ubuntu, deployed via a Plesk Git bare mirror (see
  `docs/DEPLOYMENT.md`)

## Directory map

- `app/Http/Controllers` - thin controllers; every destructive/admin action
  calls `$this->authorize(...)` or `abort_unless(...)` explicitly
- `app/Policies` + `app/Providers/AuthServiceProvider.php` - all authorization
  logic (Laravel Policies and Gates). **This is the source of truth for who
  can do what** - do not add ad-hoc role checks in views or controllers
  instead of using/extending a Policy or Gate.
- `app/Services` - integration clients and business logic, one subfolder per
  external platform (`EntraSync/`, `SuperOps/`, `Huntress/`, `Dropsuite/`,
  `M365/`, `Pax8/`) plus `Portal/` for portal-native services
  (dashboards, activity feed, product entitlements).
- `app/Jobs` - queued background work (Entra sync, SCIM provisioning,
  metric refreshes). Controllers dispatch these instead of doing slow
  external-API calls inline, to avoid gateway timeouts.
- `Brain/` - the project's own knowledge base (product requirements,
  runbooks, per-integration setup guides, decisions log). **Read
  `Brain/README.md` first** for anything integration- or ops-related; it is
  more detailed than this file for those topics. `docs/` (this audit's
  output) covers architecture/security/deployment at a higher level.
- `docs/` - audit and architecture documentation (this session's output).

## Security rules - do not casually change these

1. **Every controller action that reads/writes another user's or another
   client's data must call `$this->authorize()` (Policy) or
   `abort_unless($gate/condition, 403)` before touching the model.** Do not
   rely on route middleware alone - the `role:` middleware only checks the
   *category* of user (e.g. any admin), not which specific client/user
   record they may touch. See `app/Http/Middleware/EnsureUserHasRole.php`
   and every Policy in `app/Policies/`.
2. **`User::canAccessClient()` / `accessibleClientIds()` are the only
   correct way to scope a client-owned query to the current user.** Do not
   write ad-hoc `where('client_id', ...)` scoping logic elsewhere. An empty
   `accessibleClientIds()` means **no** clients: always apply
   `whereIn(..., $ids)` - never wrap it in `when(! empty($ids), ...)`.
3. **Admin FormRequest classes intentionally return `authorize():
   true`** - authorization is done in the controller, not the FormRequest.
   This is a deliberate, consistent pattern here; do not "fix" it by moving
   authorization into FormRequests without updating every caller. (Two
   customer-facing requests, `StoreSupportTicketRequest` and
   `StoreNewStarterRequest`, do gate in `authorize()`; and requests that
   accept a `client_id` use the `ValidatesClientAccess` validation hook so
   staff cannot write into unassigned clients.)
4. **Never commit `.env`, real credentials, or API tokens.** `.env.example`
   documents every variable name with a blank/placeholder value only.
   `.gitignore` already excludes `.env`, `.env.backup`, `.env.production`.
5. **Mass assignment:** every model declares an explicit `$fillable` array.
   Keep it that way - do not switch to `$guarded = []`.
6. **External HTTP calls must set an explicit timeout** (see
   `app/Services/*/​*ApiClient.php`) - Guzzle/Laravel HTTP client calls
   without a timeout can hang a worker indefinitely.
7. **SuperOps ticket HTML from the external API must go through
   `App\Support\SuperOpsHtml::sanitize()`** before being echoed with `{!!
   !!}` in Blade. Do not add new unescaped output of third-party or
   user-supplied content without an equivalent allowlist sanitizer.
8. **Do not introduce Redis, a message broker, Kubernetes, or a
   microservice split** without an explicit decision recorded in
   `Brain/Decisions.md` - this app is intentionally a single Laravel
   monolith on one Plesk box with database-backed queue/cache/session.

## Commands

```bash
composer install                 # PHP deps
npm install && npm run build     # frontend deps + production bundle
npm run dev                      # Vite dev server with HMR

cp .env.example .env && php artisan key:generate
php artisan migrate --seed

php artisan test                 # PHPUnit, 267 tests as of this audit
vendor/bin/pint                  # code style (see note below)
vendor/bin/pint --test           # check only, no changes

npm audit                        # frontend dependency vulnerabilities
composer audit                   # PHP dependency vulnerabilities
```

**Pint note:** `laravel/pint` is a dev dependency but there is no
`pint.json`, so it runs Laravel's default preset, which currently reports
style differences across ~60 files that were never actually run through
this preset. Do not blind-run `vendor/bin/pint` and commit the result - it
would produce a large, unreviewed diff. If code style is to be enforced,
add a `pint.json` matching the codebase's actual conventions first (see
`docs/SYSTEM_AUDIT.md` → Code Quality Review).

## Testing conventions

- `RefreshDatabase` + SQLite in-memory (`phpunit.xml`) - fast, isolated.
- `tests/Feature/Security/` holds authorization-regression tests
  (IDOR-style: "can user X touch client Y's records"). **Add a test here
  for any new cross-tenant or cross-role action.**
- Several services are gated behind `App\Services\Portal\ClientProductService`
  (product entitlements) and platform-readiness config (e.g.
  `services.entra_sync.client_id/secret`). Tests that exercise those
  services must set both, or they will see a fail-closed 403/`RuntimeException`
  rather than the behaviour under test - this is what caused most of the
  stale-test failures fixed in this audit.

## What NOT to do

- Do not add a REST/JSON API surface, CORS config, or SPA build without a
  clear product reason - none exists today and none is needed for the
  current Blade-rendered app.
- Do not re-architect the admin role model (`App\Enums\UserRole`) without
  updating every Policy, Gate, and the `tests/Feature/Security/` suite in
  the same change.
- Do not disable CSRF protection, session cookie `secure`/`http_only`
  flags, or the `SecurityHeaders` middleware for convenience during
  development - fix the actual local-dev issue instead (see
  `Brain/LocalDevelopment.md`).
- Do not delete anything in `Brain/` - it is the team's own documentation
  system, not code, and is the primary source of institutional knowledge
  for this project.

## Further reading

- `docs/ARCHITECTURE.md` - system architecture, request flow, integrations
- `docs/SECURITY.md` - auth/authz model, secrets handling, trust boundaries
- `docs/DEPLOYMENT.md` - exact production deployment steps and required env vars
- `docs/SYSTEM_AUDIT.md` - this audit's full findings and verification results
- `Brain/README.md` - the project's own knowledge base index
