# Deployment handoff - On IT Portal

**Audience:** ServerManager / ProjectManager (or any generic deployer).  
**Generated:** 2026-10-21  
**Source commit:** `7f0ade1` (GitHub `main` at export; confirm with `git rev-parse HEAD` before deploy)  
**Export location (workstation):** `C:\Dev\EXPORTS\OnIT-Portal\` (not committed)  
**Not a ServerManager contract:** this file describes how *this* app runs. It does not invent a platform manifest format.

---

## 1. Project identity

| Field | Value |
|---|---|
| Name | On IT Portal |
| Short description | Laravel 11 MSP customer/staff portal: Entra SSO, SuperOps/Huntress/Dropsuite/Pax8 aggregation, multi-tenant isolation |
| Primary language | PHP 8.2+ |
| Framework | Laravel 11 (Blade + Vite 5 / Tailwind / Alpine) |
| Package manager | Composer + npm (Vite assets) |
| **Deployment type** | **PHP / Laravel application (non-Docker)** |
| Suggested hosting | Linux reverse proxy (Nginx/Apache) + PHP-FPM 8.2+ + MySQL/MariaDB. Current production uses **Plesk on Ubuntu**. |
| WordPress? | **No** |
| Docker/Compose in repo? | **No** (none present) |

Treat this as one application among many. Do not hardcode its hostname, paths, or Entra redirect URI into the platform; inject them via environment and host config.

---

## 2. How it runs

### Runtime versions (verified locally 2026-10-21)

| Runtime | Required | Measured on this workstation |
|---|---|---|
| PHP | ^8.2 (prod commonly 8.3) | 8.2.31 |
| Composer | 2.x | 2.10.1 |
| Node.js | 18+ **only if rebuilding assets** | v24.16.0 |
| Laravel | 11.x | 11.54.0 (`php artisan about`) |

PHP extensions: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`.

### Installation (application root = repo root)

```bash
composer install --no-dev --optimize-autoloader
# Frontend: either use committed public/build/ OR rebuild:
npm ci && npm run build
cp .env.example .env   # first install only
php artisan key:generate
# Edit .env (see section 4)
php artisan migrate --force
php artisan db:seed --force   # first install / when seeders required (e.g. PortalLinkSeeder)
```

Working directory for all `artisan` / `composer` / `npm` commands: **repository root** (parent of `artisan` and `public/`).

### Build

| Step | Command | Notes |
|---|---|---|
| PHP deps | `composer install --no-dev --optimize-autoloader` | Required on every fresh tree |
| Front assets | `npm ci && npm run build` | Optional if `public/build/` already present from Git |
| Laravel caches | `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan optimize` | Production |

### Test

```bash
php artisan test
# or focused:
php artisan test tests/Unit/Support/MailDomainTest.php
```

PHPUnit uses SQLite in-memory (`phpunit.xml`). Local results 2026-10-21:
- `ClientMailDomainTest` - **4 passed**
- `MailDnsHealthServiceTest` / `MailDomainTest` - **passed** in earlier same-day runs
- Full `php artisan test` suite - **not** re-run in this preparation pass (report as unverified)

### Production “start”

This is **not** a long-running Node process. Production serves `public/` via PHP-FPM behind a reverse proxy.

| Process | Command / mechanism | Required? |
|---|---|---|
| Web | PHP-FPM + proxy; document root = `public/` | Yes |
| Scheduler | `php artisan schedule:run` **every minute** | Yes |
| Queue workers | `php artisan queue:work database --queue=high,default --sleep=1 --tries=3` (prefer **2** workers) | Yes |

Current production pattern: systemd timers firing `schedule:run` and two short-lived `queue:work … --stop-when-empty --max-time=55` drains per minute (see `Brain/Deployment.md`). Supervisor `numprocs=2` is also valid.

**Do not** use `php artisan serve` in production.

### Scheduled tasks (from `routes/console.php`)

| Cadence | Command / action |
|---|---|
| Every minute (gated) | `portal:sync-entra-users` when Entra sync enabled |
| Every minute | `portal:prewarm-client-dashboards` |
| Every minute | Scheduler heartbeat (Integration Health) |
| Daily 02:15 app TZ | `portal:capture-metric-snapshots` |
| Daily 03:20 | `model:prune` ActivityLog |
| Daily 03:30 | `queue:prune-failed --hours=720` |
| Daily 03:40 | `portal:prune-expired-cache` |

### Services / ports

| Service | Role | Network |
|---|---|---|
| MySQL / MariaDB 10.6+ or 8.0+ | App DB, sessions, cache, queues | Private; not public |
| PHP-FPM | App runtime | Private (proxy → FPM socket/port) |
| Reverse proxy | TLS termination | Public 80/443 **may already be owned** by host proxy - app should listen on an **internal** HTTP port or Unix socket only |
| Redis | **Not used** | - |
| Docker | **Not used** | - |

Outbound HTTPS required to: Microsoft Entra/Graph, SuperOps, Huntress, Dropsuite, Pax8 (as configured).

---

## 3. Suggested hosting approach

1. Provision Linux host (or reuse existing reverse-proxy host).
2. Create isolated app user / PHP-FPM pool; private MySQL database + least-privilege DB user.
3. Place code on persistent disk; set document root to `public/`.
4. Terminate TLS at the existing reverse proxy; proxy to PHP-FPM. Assume **80/443 are not free** for exclusive use by this app alone.
5. Persist `.env`, `storage/`, and the database outside any disposable layer.
6. Run minute scheduler + two queue workers (systemd, Supervisor, or equivalent).
7. Configure Microsoft Entra redirect URI to `{APP_URL}/auth/microsoft/callback`.

---

## 4. Environment variables

Copy from `.env.example`. **Never commit real `.env`.** Mark **SECRET** where values must not appear in logs or exports.

### Application

| Variable | Secret? | Notes |
|---|---|---|
| `APP_NAME` | No | Display name |
| `APP_ENV` | No | Must be `production` live |
| `APP_KEY` | **SECRET** | `php artisan key:generate` |
| `APP_DEBUG` | No | Must be `false` live |
| `APP_URL` | No | Public origin (HTTPS) |
| `APP_TIMEZONE` | No | Default UTC |
| `ACTIVITY_LOG_RETAIN_DAYS` | No | Default 90 |

### Database / session / queue / cache

| Variable | Secret? | Notes |
|---|---|---|
| `DB_CONNECTION` | No | `mysql` production |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` | No | Private network host |
| `DB_PASSWORD` | **SECRET** | |
| `SESSION_DRIVER` | No | `database` |
| `SESSION_SECURE_COOKIE` | No | `true` on HTTPS |
| `QUEUE_CONNECTION` | No | `database` |
| `CACHE_STORE` | No | `database` |
| `CACHE_PREFIX` | No | |
| `DB_QUEUE_RETRY_AFTER` | No | Default 900; must stay above longest job `$timeout` (600) |

### Bootstrap admin

| Variable | Secret? | Notes |
|---|---|---|
| `SUPER_ADMIN_EMAIL` | No (PII-ish) | UPN seeded as first `super_admin`; SSO-only so a fresh DB needs this |

### Microsoft Entra (required for login)

| Variable | Secret? | Notes |
|---|---|---|
| `MICROSOFT_CLIENT_ID` | No | App registration ID |
| `MICROSOFT_CLIENT_SECRET` | **SECRET** | |
| `MICROSOFT_TENANT_ID` | No | Often `organizations` |
| `MICROSOFT_REDIRECT_URI` | No | `{APP_URL}/auth/microsoft/callback` |

### Integrations (optional until product enabled)

| Variable | Secret? | Notes |
|---|---|---|
| `SUPEROPS_API_TOKEN` | **SECRET** | |
| `SUPEROPS_SUBDOMAIN` / portal URL paths | No | |
| `HUNTRESS_ENABLED` / `HUNTRESS_API_KEY` / `HUNTRESS_API_SECRET` | key/secret **SECRET** | Default off |
| `DROPSUITE_ENABLED` / tokens | tokens **SECRET** | Default off |
| `USECURE_*` | key **SECRET** | Keep disabled until probed; do not ship unfinished local skeleton |
| `PAX8_*` / portal URLs | No (SSO launch URLs) | |
| `ENTRA_SYNC_ENABLED` / `ENTRA_SYNC_CLIENT_ID` / `ENTRA_SYNC_CLIENT_SECRET` | secret **SECRET** | Graph app perms |

Full placeholder list: repository `.env.example`.

---

## 5. Dependencies and persistence

### Must survive updates

| Path / resource | Why |
|---|---|
| `.env` | Secrets and host config |
| `storage/` (esp. `storage/logs`, `storage/app`, framework dirs) | Logs, uploads, cache files |
| `bootstrap/cache/` | Writable for optimize |
| MySQL database | All app state, sessions, jobs, cache rows |
| `public/build/` | If you do not rebuild on deploy |

### Disposable on each release

Application PHP/Blade/source under `app/`, `resources/`, `routes/`, `vendor/` (reinstall via Composer).

### Permissions

Web/PHP user must write `storage/` and `bootstrap/cache/` (typically `775`, owner web user / group).

---

## 6. Public routes and health

| Item | Value |
|---|---|
| Document root | `public/` |
| Health check | `GET /up` (Laravel 11 health route in `bootstrap/app.php`) - expect HTTP 200 |
| Login | `GET /login` |
| OAuth callback | `GET /auth/microsoft/callback` (must match Entra app registration) |
| Staff ops | `/admin/...` (authenticated) |

Trust proxies: app trusts `X-Forwarded-*` (`bootstrap/app.php`) so HTTPS detection works behind a reverse proxy.

---

## 7. Migrations and seeders

**Order:**

1. Deploy code + `composer install`
2. Ensure `.env` DB settings point at the target database
3. `php artisan migrate --force`
4. `php artisan db:seed --force` when links/seed data required (production often runs `PortalLinkSeeder` after deploy)
5. Then cache/optimize; restart/reload queue workers

There is no separate migration tool. Pending product work on `main` may include mail-domain migrations (`primary_domain`, `client_domains`) - always run migrate after pulling.

**Do not** run `php artisan cache:clear` on a routine production update - it wipes dashboard snapshot caches. Prefer `route:clear` / `config:clear` / `view:clear` then re-cache, or the documented post-deploy block in `Brain/Deployment.md`.

---

## 8. Backup, restore, rollback

| Asset | Backup | Restore / rollback |
|---|---|---|
| Database | mysqldump / host backup | Restore dump; then `migrate --force` only if schema behind |
| `.env` | Secure vault / host backup (not Git) | Restore file; `config:cache` |
| `storage/` | Include in filesystem backup | Restore tree; fix ownership |
| Code | Git tag / commit SHA | Redeploy previous SHA; reinstall Composer; migrate only if safe |

Rollback of schema-forward migrations may need a DBA review - prefer forward fixes.

---

## 9. Resources (estimates vs measured)

| Resource | Classification | Guidance |
|---|---|---|
| PHP `memory_limit` | Estimate / configured | >= 256M per FPM worker |
| App disk (code without vendor) | Measured ~2.3 MB layout zip; with vendor/node larger | Allow several hundred MB for `vendor` + logs |
| CPU / RAM host | **Estimate** | Small VPS class is enough for low concurrent staff/customer use; queue workers add CPU during Entra/M365 jobs |
| Measured host quirks | Documented in Brain | Production host previously saw cron RSS bloat; prefer systemd timers |

No formal load-test numbers in-repo.

---

## 10. SSH / ops access

- Production ops historically use SSH to the Plesk Ubuntu host for Git archive deploy, artisan, and systemd.
- Platform should support: non-root deploy user, writable app tree, ability to run PHP CLI as the pool user, and manage supervisor/systemd units.
- Do not store deploy private keys in the application repository.

---

## 11. Manual steps / blockers / unsupported for a generic platform

**Manual (outside pure code deploy):**

1. Microsoft Entra app registration + redirect URI + Graph application permissions / admin consent.
2. SuperOps / Huntress / Dropsuite / Pax8 credentials and customer mapping.
3. DNS + TLS for the chosen hostname.
4. First-time DB create and `.env` population.
5. Enable and verify scheduler + queue workers.

**Blockers / caveats for ServerManager:**

| Item | Detail |
|---|---|
| No Docker evidence | Platform must support **PHP-FPM + Composer** (or build its own image from this handoff - not supplied) |
| No Redis | Do not inject Redis unless product decides otherwise |
| Background workers | Platform **must** run scheduler + queue workers or the UI will look empty/stale |
| Entra dependency | App has **no password login**; without Microsoft config, only health/public pages work |
| Hardcoded URLs in copy | Some onboarding/auth error strings mention a production hostname; configure `APP_URL` correctly and treat copy as content debt |
| Local dirty tree | Workstation may have uncommitted usecure WIP - **do not** ship that; export from Git `main` |
| Mail domains Phase A | On GitHub `main` but may not be on the current live host until a separate authorised deploy + migrate |

**Not claimed:** compatibility with any specific ServerManager API or UI contract (not inspected in this repo).

---

## 12. Verification checklist

After deploy:

1. `GET /up` → 200  
2. `GET /login` → 200, no debug stack traces (`APP_DEBUG=false`)  
3. Microsoft sign-in round-trip succeeds  
4. `php artisan migrate:status` shows no pending migrations  
5. Scheduler heartbeat updates (staff Integration Health) within ~2 minutes  
6. Queue: a refresh job completes (`jobs` / `failed_jobs` tables healthy)  
7. `composer validate` and a smoke `php artisan test` in CI/staging when possible  

---

## 13. Related docs in this repository

| Doc | Role |
|---|---|
| `AGENTS.md` | Agent/engineering rules |
| `Brain/Deployment.md` | Full production runbook (Plesk-specific) |
| `docs/DEPLOYMENT.md` | Condensed audit deployment notes |
| `Brain/Handover.md` | Product/ops status for humans |
| `.env.example` | Placeholder env schema |

---

## 14. Capabilities ServerManager / ProjectManager must provide

To deploy this app generically, the platform needs:

1. **Git or ZIP ingest** of a Laravel tree (no Docker required).  
2. **Composer install** on the target (PHP 8.2+ CLI).  
3. Optional **Node build** or accept committed `public/build/`.  
4. **PHP-FPM + reverse-proxy** wiring with configurable internal listen address (not assuming exclusive 80/443).  
5. **MySQL/MariaDB** provisioning and private networking.  
6. **Persistent volumes** for `.env`, `storage/`, database.  
7. **Env injection** for secrets without committing them.  
8. **Post-deploy hooks:** migrate, seed (selective), optimize, reload PHP-FPM.  
9. **Long-running or minute-scheduled processes:** `schedule:run` + `queue:work` (x2).  
10. **Health probe** against `/up`.  
11. **Rollback** to previous release SHA + DB backup restore.  

Optional later: Dockerfile generation *by the platform* from this handoff - not part of this repository today.
