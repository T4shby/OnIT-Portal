# Deployment - On IT Portal

Production target: **Plesk on Ubuntu**, PHP-FPM, MySQL/MariaDB, no Docker,
no Redis, no queue broker. This is a condensed, audit-oriented version of
the full runbook in `Brain/Deployment.md` - read that file for the
host-specific detail (systemd unit files, Plesk UI click-paths, known
Plesk/cron quirks on this specific server). This file exists so deployment
requirements are documented outside the team's internal Brain notes too.

## Target environment

| Component | Requirement |
|---|---|
| OS | Ubuntu Server 20.04+/22.04+ |
| Panel | Plesk |
| PHP | 8.2+ with PHP-FPM (extensions: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`) |
| Web server | Apache or Nginx (via Plesk), Let's Encrypt SSL |
| Database | MariaDB 10.6+ or MySQL 8.0+ |
| Node.js | 18+ (only needed if rebuilding frontend assets - see below) |

## Deployment pipeline

```
git push origin main  →  GitHub  →  Plesk Git pull (bare mirror)  →  live docroot
                                                                          │
                                                                          ▼
                                                      post-deploy artisan block (below)
```

Production deploys from the **`main`** branch only, via Plesk's Git
integration (Domains → your domain → Git → **Pull Updates** → **Deploy**).
The live docroot has no `.git` directory - Plesk copies files in from its
bare mirror on deploy. Do not `scp`/unzip files directly into the live
docroot except as a documented emergency fallback (see `Brain/Deployment.md`)
- doing so desyncs the live code from what GitHub/Plesk believe is
deployed.

**Frontend assets are committed to the repository** (`public/build/`), so
a deploy does not require running `npm run build` on the server. Rebuild
and commit them locally (`npm run build`) whenever frontend source changes,
the same way you would commit any other generated-but-versioned artifact
in this repo.

## First-time setup steps

1. **Provision:** Ubuntu + Plesk, domain/subdomain DNS, SSL certificate.
2. **PHP:** select PHP 8.2+ for the domain, enable the extensions listed
   above, `memory_limit >= 256M`, `max_execution_time = 60`.
3. **Database:** create the database and a dedicated database user with
   full privileges on it only (not a shared/admin DB user).
4. **Code:** wire up the Plesk Git integration as above, or bootstrap via
   one-time SFTP upload if Git is not yet available (see
   `Brain/Deployment.md` "Option B").
5. **Dependencies:**
   ```bash
   cd /path/to/live/docroot
   composer install --no-dev --optimize-autoloader
   ```
6. **Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Then edit `.env` with production values - see **Required environment
   variables** below. Never commit this file.
7. **Migrate & seed:**
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   php artisan portal:purge-demo-data --force   # one-time, for older installs only
   ```
8. **Permissions:**
   ```bash
   chown -R www-data:<plesk-group> storage bootstrap/cache
   chmod -R 775 storage bootstrap/cache
   ```
9. **Optimize:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan optimize
   ```
10. **Scheduler & queue workers:** this app has **no** long-running
    supervisor process built in - `schedule:run` and `queue:work` must be
    invoked externally, every minute, by cron or (preferred on this host)
    systemd timers. See `Brain/Deployment.md` for the exact unit files used
    in production; at minimum you need:
    - `php artisan schedule:run` every minute
    - one or more `php artisan queue:work database --queue=high,default
      --stop-when-empty --max-time=55 --tries=3` workers running every
      minute (short-lived, restarted each minute - there is no persistent
      worker daemon by default)
    - Jobs run on two queue names only, `high` and `default`; every job
      class uses the `database` connection, so the worker above covers all
      of them. A job longer than 55s keeps its worker running past the next
      minute tick; that is safe only because `config/queue.php` sets the
      database `retry_after` to 900s (above the longest job timeout, 600s).
      Do not lower `DB_QUEUE_RETRY_AFTER` below that, or a second worker
      re-runs the same job while the first is still going.

## Post-deploy checklist (every deploy, not just first-time)

- [ ] `composer install --no-dev --optimize-autoloader` if `composer.lock` changed
- [ ] `php artisan migrate --force` if new migrations were added
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] Confirm `public/build/manifest.json` matches the deployed frontend
      source (rebuild locally and commit if it doesn't)
- [ ] Check `storage/logs/laravel.log` after deploy for boot-time errors
- [ ] Confirm scheduler heartbeat is current: Admin → Integration Health
      page shows a recent "Scheduler" tick
- [ ] Sign in once with Microsoft in a normal browser window (see "Microsoft
      sign-in state check" below)

### Microsoft sign-in state check (eighth audit pass)

Sign-in always verifies the OAuth `state` against a short-lived
`__Host-onit_oauth_state` cookie (not the session), and the old
`MICROSOFT_OAUTH_STATELESS` variable is gone. On the first deploy that
includes this change:

1. Delete `MICROSOFT_OAUTH_STATELESS` from the production `.env` if present
   (it is ignored now), then `php artisan config:cache`.
2. `APP_URL` and `MICROSOFT_REDIRECT_URI` must use the same host
   (`app.onit.ltd`). If `/auth/microsoft` is reached on another host it
   bounces once to the redirect URI's host, so the cookie is set where
   Microsoft returns the browser.
3. Sign in with Microsoft once. Then check `storage/logs/laravel.log` for
   `Microsoft OAuth state check failed` warnings over the next day or so. The
   `reason` field (`missing_cookie` / `mismatch` / `expired`) and
   `has_state_cookie` say why. An occasional `expired`, or a `mismatch` from
   a reused callback URL, is expected. Repeated `missing_cookie` for many
   users would mean the cookie is not surviving the redirect. There is no
   config switch to turn the check off: roll back the code (no migration is
   involved) and report it.

## Required environment variables

Names and purpose only - see `.env.example` for the authoritative,
up-to-date list and `docs/SECURITY.md` for which are required vs.
feature-gated.

| Variable | Purpose | Required? |
|---|---|---|
| `APP_ENV` | Set to `production` | Yes |
| `APP_DEBUG` | Set to `false` (never `true` in production - it leaks stack traces and config to error pages) | Yes |
| `APP_KEY` | Encryption key, generate with `artisan key:generate` | Yes |
| `APP_URL` | Public HTTPS URL | Yes |
| `DB_*` | Database connection | Yes |
| `SESSION_DRIVER` | `database` | Yes |
| `SESSION_SECURE_COOKIE` | Set to `true` in production (blank/unset now defaults to `true` when `APP_ENV=production`) | Recommended - see `docs/SECURITY.md` |
| `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` / `MICROSOFT_TENANT_ID` / `MICROSOFT_REDIRECT_URI` | Entra ID SSO | Yes - login is disabled without these |
| `ADMIN_CONSENT_LINK_TTL_HOURS` | Hours a Connect Microsoft / Re-consent link stays valid (clamped 1-336) | No, default 24 |
| `QUEUE_CONNECTION` | `database` | Yes |
| `DB_QUEUE_RETRY_AFTER` | Seconds before a reserved job is re-run by another worker. Must exceed the longest job `$timeout` (600s) | No, default 900 (`config/queue.php`) |
| `ACTIVITY_LOG_RETAIN_DAYS` | Audit log retention (days) | No, default 90 |
| `SUPEROPS_API_TOKEN`, `SUPEROPS_SUBDOMAIN`, `SUPEROPS_*` | SuperOps PSA integration | Feature-gated |
| `ENTRA_SYNC_ENABLED`, `ENTRA_SYNC_CLIENT_ID`, `ENTRA_SYNC_CLIENT_SECRET` | Entra group sync, M365 directory, SCIM | Feature-gated |
| `HUNTRESS_ENABLED`, `HUNTRESS_API_KEY`, `HUNTRESS_API_SECRET` | Huntress security dashboard | Feature-gated, off by default |
| `DROPSUITE_ENABLED`, `DROPSUITE_RESELLER_TOKEN`, `DROPSUITE_AUTH_TOKEN` | Dropsuite backup dashboard | Feature-gated, off by default |
| `PAX8_*` | Pax8 SSO launch | Feature-gated |
| `SUPER_ADMIN_EMAIL` | Microsoft sign-in (UPN) of the first `super_admin`; `db:seed` (`UserSeeder`) creates/updates this account. Login is SSO-only and needs a pre-provisioned user, so a fresh install has no admin without it | Yes on first install |

## Rollback

Plesk Git deploy supports pulling a prior commit and re-running "Deploy".
After rolling back code, also check whether the rolled-back version
requires a migration rollback (`php artisan migrate:rollback`) - only do
this if you are certain no destructive migration ran since the target
commit, since some migrations in this app are one-way data transforms
(e.g. `2026_07_15_120000_migrate_client_user_to_client_requester.php`).

## What this document does not cover

Host-specific systemd unit file contents, the exact Plesk UI click-path,
known cron/Plesk quirks on the current production host, and per-integration
setup (Entra app registration, SuperOps SCIM, Huntress/Dropsuite API keys)
are all documented in `Brain/Deployment.md` and the other `Brain/*.md`
files it links to - read those before a first production deploy.
