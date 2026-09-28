# Security Model - On IT Portal

This document describes the authentication model, the authorization model,
how secrets are handled, and the trust boundaries in this application. It
intentionally contains no secret values - only variable names, purposes,
and whether each is required.

## Authentication

- **Microsoft Entra ID (Azure AD) SSO only**, via Laravel Socialite +
  `socialiteproviders/microsoft-azure`. There is no local username/password
  login and no password reset flow - all identity is federated.
- **No self-service signup.** `MicrosoftAuthController::callback()` matches
  the Microsoft identity to an *existing* `users` row (first by
  `entra_object_id`, then by lower-cased email) and refuses to log in
  anyone who does not already have a row. Accounts are provisioned only by
  an administrator (`Admin\UserController`, or automated Entra group sync -
  see `Brain/EntraGroupSync.md`).
- Login is additionally refused when: `is_active = false`,
  `portal_login_enabled = false` (used for shared mailboxes synced for
  ticketing but not meant to sign in), or the user's `Client` is inactive.
- `/auth/microsoft/callback` is rate-limited (`throttle:auth-callback`, 6/min
  per IP) via `RateLimiter::for('auth-callback', ...)` in
  `AppServiceProvider`.
- CSRF protection is Laravel's default (`ValidateCsrfToken` in the `web`
  middleware group) and is **not** disabled anywhere in this app
  (`bootstrap/app.php` registers no CSRF exceptions).
- Session cookie: `SESSION_DRIVER=database`, `http_only=true` by default,
  `SESSION_SECURE_COOKIE` **must be set to `true` in production**
  (documented in `Brain/Deployment.md`'s example `.env`; `.env.example`
  lists the variable blank for local HTTP development - a second audit
  pass found it was previously missing from `.env.example` entirely and
  added it). `SecurityHeaders`
  middleware adds `Strict-Transport-Security` only when `app()->isProduction()`.
- Microsoft OAuth tokens obtained at login (`access_token`/`refresh_token`)
  are stored on the `users.microsoft_tokens` column with Laravel's
  `encrypted:array` cast (encrypted at rest using `APP_KEY`).

## Authorization model

All authorization is enforced **server-side, per action**, using Laravel
Policies and Gates - never trust a hidden field, a route parameter, or
"the UI didn't show that button" as an access control.

1. **Role middleware** (`EnsureUserHasRole`, aliased `role:`) gates entire
   route groups by role *category* - e.g. every `/admin/*` route requires
   `super_admin` or `account_manager`; `/admin/team/*` further requires
   `super_admin` only. This is coarse - it answers "is this an admin
   route at all", not "can this admin touch this specific record".
2. **Policies** (`app/Policies/*`, registered in `AuthServiceProvider`)
   answer the fine-grained question for a specific model instance:
   `ClientPolicy`, `UserPolicy`, `PortalLinkPolicy`, `ClientNoticePolicy`,
   `ClientRecommendationPolicy`, `ClientOpportunityPolicy`,
   `SettingPolicy`, `ActivityLogPolicy`. Every controller method that
   reads or mutates a specific record calls `$this->authorize('action',
   $model)` before doing so.
3. **Gates** (defined in `AuthServiceProvider::boot()`) answer
   feature/product-level questions that don't map to a single Eloquent
   model: `access-admin`, `manage-all-clients`, `view-client-admin-dashboard`,
   `view-organisation-wide`, `view-my-systems`, `view-m365-directory`,
   `view-huntress-security`, `access-client-billing`, `contact-support`.
   These are applied both as route middleware (`can:view-m365-directory`)
   and inside controllers for actions without a dedicated route-level
   check.
4. **Tenant scoping**: `User::canAccessClient(int $clientId)` and
   `User::accessibleClientIds()` (`app/Models/User.php`) are the single
   source of truth for "which clients can this user see", based on role:
   - `super_admin` - all clients
   - `account_manager` - only clients explicitly assigned via the
     `client_user` pivot (`assignedClients()`)
   - `client_admin` / `client_billing_admin` / `client_requester` - only
     their own `client_id`
   Every admin index/listing query (`Admin\ClientController::index`,
   `Admin\UserController::index`, `Admin\PortalLinkController::index`,
   `Admin\NoticeController::index`, `Admin\ActivityLogController::index`,
   etc.) filters through `accessibleClientIds()`. (A previously-unused
   `EnsureClientAccess` middleware/`client.access` alias that duplicated
   this check for route-bound `client_id`s was removed in the fourth audit
   pass - see `docs/SYSTEM_AUDIT.md`; it was applied to zero routes.)
5. **FormRequests intentionally return `authorize(): true`** - this
   codebase does authorization in the controller, consistently. Do not
   assume a FormRequest is unauthenticated just because `authorize()`
   returns `true`; check the controller.
6. **Cross-tenant write guards**: `ValidatesClientAccess` (used by
   `StoreUserRequest`/`UpdateUserRequest`) additionally checks that an
   `account_manager` cannot create or move a user into a client they are
   not assigned to, and cannot move a user *between* clients at all
   (Super Admin only) - covered by
   `tests/Feature/Security/UserClientAccessTest.php`.

### Role summary

| Role | Scope |
|---|---|
| `super_admin` | Full platform access, all clients, only role that can manage On IT staff/Team and delete clients/users |
| `account_manager` | Admin for clients explicitly assigned to them |
| `client_admin` | Organisation-wide view for their own client (all tickets/devices/directory for that client) |
| `client_billing_admin` | Their own client, plus billing access |
| `client_requester` | Their own client only, personal tickets/devices only (not org-wide) |

## Secrets handling

- No secret values are committed to this repository. `.env.example`
  documents every environment variable by name with a blank or clearly
  non-functional placeholder value (`admin@onit.example`, etc.).
- `.gitignore` excludes `.env`, `.env.backup`, `.env.production`, and
  `auth.json` (Composer credentials).
- All third-party credentials are read via `env()` **only inside
  `config/*.php`** and accessed elsewhere via `config('services....')` -
  this is the Laravel-recommended pattern and lets `php artisan
  config:cache` work correctly in production. Verified by repository-wide
  grep: no `env()` calls outside `config/` in `app/`, `routes/`, or
  `resources/`.
- `microsoft_tokens` on the `users` table is the one piece of
  credential-like data stored in the database; it uses Laravel's
  `encrypted:array` Eloquent cast, so it is encrypted at rest with
  `APP_KEY` and never appears in plaintext in the database or in logs
  (Eloquent casts apply before any logging of the model).
- SCIM secret tokens submitted via `Admin\ClientController::applyScim()`
  are passed straight through to the queued job and to Microsoft Graph;
  they are not persisted to the database or activity log (only the SCIM
  host, via `parse_url(...,  PHP_URL_HOST)`, is logged).

### Environment variables (see `.env.example` for the full, current list)

Grouped by purpose; "required" means the feature is broken/unavailable
without it, not that the app fails to boot.

| Group | Required in production | Notes |
|---|---|---|
| `APP_*`, `DB_*` | Yes | Standard Laravel/DB config |
| `SESSION_SECURE_COOKIE` | Yes (set `true`) | Not enforced by app code - must be set in `.env` |
| `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` / `MICROSOFT_TENANT_ID` / `MICROSOFT_REDIRECT_URI` | Yes | Entra ID app registration; without these, login is disabled with an explicit error message |
| `SUPEROPS_API_TOKEN`, `SUPEROPS_SUBDOMAIN` | Feature-gated | Support tickets / SSO launch unavailable without it |
| `ENTRA_SYNC_ENABLED`, `ENTRA_SYNC_CLIENT_ID`, `ENTRA_SYNC_CLIENT_SECRET` | Feature-gated | Group sync, M365 directory, SCIM provisioning all gated on this |
| `HUNTRESS_*` | Feature-gated, default disabled | `HUNTRESS_ENABLED=false` by default |
| `DROPSUITE_*` | Feature-gated, default disabled | `DROPSUITE_ENABLED=false` by default |
| `PAX8_*` | Feature-gated | SSO launch to Pax8 |
| `SUPER_ADMIN_EMAIL` | Informational | Used for display/contact only |

## Trust boundaries

- **Browser ↔ App**: session-cookie auth, CSRF-protected, no public API.
- **App ↔ MySQL**: parameterized queries throughout (Eloquent query
  builder; the few raw fragments use `whereRaw('LOWER(email) = ?', [...])`
  with bound parameters - verified by repo-wide grep, no string
  concatenation into SQL anywhere).
- **App ↔ External platforms** (Microsoft Graph, SuperOps, Huntress,
  Dropsuite, Pax8): outbound only, every HTTP call has an explicit
  timeout (verified - see `docs/SYSTEM_AUDIT.md`). Responses from
  SuperOps (ticket HTML) are treated as untrusted and passed through
  `App\Support\SuperOpsHtml::sanitize()` (tag allowlist, all attributes
  stripped) before being rendered unescaped in Blade.
- **App ↔ Queue workers**: same trust level as the app itself (same
  codebase, same DB) - not a separate trust boundary.
- No public JSON/REST API exists; no CORS configuration is present or
  needed.

## Known limitations / accepted risk (see `docs/SYSTEM_AUDIT.md` for detail)

- Single-file log channel by default (`LOG_STACK=single`) - no automatic
  rotation. Low risk at current scale; recommend switching to `daily` in
  production if disk usage becomes a concern.
- `SESSION_SECURE_COOKIE` is not forced to `true` by application code (it
  is `env()`-driven with no default) - it is documented as required in
  production, but a misconfigured `.env` could leave it off. Consider
  defaulting it to `true` when `APP_ENV=production` in a future change.
