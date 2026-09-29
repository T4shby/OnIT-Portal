# On IT Portal - Deployment

Production hosting on Plesk/Ubuntu. For local Windows development, see [LocalDevelopment.md](LocalDevelopment.md).

## Target Environment

| Component | Requirement |
|---|---|
| OS | Ubuntu Server (20.04+ or 22.04+) |
| Panel | Plesk |
| PHP | 8.2+ with PHP-FPM |
| Web server | Apache or Nginx (via Plesk) |
| Database | MariaDB 10.6+ or MySQL 8.0+ |
| SSL | Let's Encrypt via Plesk |

## Pre-Deployment Checklist

- [ ] Ubuntu server provisioned with Plesk
- [ ] Domain/subdomain DNS pointing to server
- [ ] MariaDB/MySQL database created in Plesk
- [ ] PHP 8.2+ selected for the domain
- [ ] Microsoft Entra ID app registration completed (multi-tenant)
- [ ] SSL certificate installed

## Plesk Setup Steps

### 1. Create Domain/Subdomain

> **On IT production URL:** `https://app.onit.ltd` - the Laravel On IT Portal.  
> **`portal.onit.ltd`** is the SuperOps requester portal (CNAME to SuperOps) - do not host Laravel there.

1. Log in to Plesk
2. Add Subdomain **`app.onit.ltd`**
3. Set document root to the Laravel `public` folder, e.g. `/app.onit.ltd/public`

### 2. Configure PHP

1. Go to domain → PHP Settings
2. Select PHP 8.2 or higher
3. Enable extensions: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`
4. Set `memory_limit` to at least `256M`
5. Set `max_execution_time` to `60`

### 3. Create Database

1. Go to Databases → Add Database
2. Database name: `onit_portal`
3. Create a database user with full privileges
4. Note credentials for `.env` configuration

### 4. Deploy Application Code

**Production uses GitHub + Plesk Git + (optional) Laravel Toolkit.** Do **not** treat “no `.git` in the live path” as “the server has no GitHub”.

| Piece | Location / value |
|---|---|
| GitHub remote | `git@github.com:T4shby/OnIT-Portal.git` |
| Plesk Git bare mirror | `/var/www/vhosts/onit.ltd/git/laravel_af3fd1` |
| Live site (deploy path) | `/var/www/vhosts/onit.ltd/app.onit.ltd` - **no** `.git` (Plesk copies files here) |
| Subscription deploy key | `/var/www/vhosts/onit.ltd/.ssh/id_rsa` (wired to GitHub for this vhost) |
| Branch | **`main` only** for production |

**Correct pipeline (always):**

```text
Laptop → git push origin main → GitHub
       → Plesk Git pull (bare mirror) → deploy into app.onit.ltd
       → post-deploy artisan block (below)
```

**Plesk UI:** Domains → **app.onit.ltd** → **Git** (repo `laravel_af3fd1`) → branch **main** → **Pull Updates** → **Deploy**.

**Wrong / emergency-only:** `scp` or unzip straight into `app.onit.ltd` without updating the Plesk bare mirror. That made code “live but not on GitHub” and “live without a Plesk deploy record” - avoid it. Hotfix only if GitHub is unreachable; then still push and re-deploy via Plesk path the same day.

**Option B: SFTP (bootstrap only)**

1. Upload all files to `app.onit.ltd` via SFTP **once** if Git is not yet wired
2. Ensure `.env` is configured on server (never commit `.env`)
3. Move to GitHub + Plesk Git as soon as possible

### 5. Install Dependencies

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
export PATH="/opt/plesk/php/8.3/bin:$PATH"
composer install --no-dev --optimize-autoloader
```

### 6. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with production values:

```env
APP_NAME="On IT Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.onit.ltd

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=onit_portal
DB_USERNAME=onit_portal_user
DB_PASSWORD=your-secure-password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database

# Optional. Admin activity_logs retention (days). Default 90 if omitted.
# ACTIVITY_LOG_RETAIN_DAYS=90

MICROSOFT_CLIENT_ID=your-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=organizations
MICROSOFT_REDIRECT_URI=https://app.onit.ltd/auth/microsoft/callback

SUPEROPS_API_TOKEN=
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_TECHNICIAN_LOGIN_PATH="/#/technician/login"
SUPEROPS_REQUESTER_LOGIN_PATH="/#/requester/login"
SUPEROPS_LOGIN_HINT_ENABLED=true
SUPEROPS_SSO_ENABLED=true
SUPEROPS_AUTO_OPEN_AFTER_LOGIN=false
# Adaptive prewarm / Entra cadence: Staff Admin → Integration Health (DB settings), not .env
SUPEROPS_DASHBOARD_STALE_MINUTES=10080
SUPEROPS_DASHBOARD_REFRESH_COOLDOWN_SECONDS=60
SUPEROPS_DASHBOARD_MAX_PAGES=10

DROPSUITE_ENABLED=false
DROPSUITE_API_URL=https://dropsuite.uk/api
DROPSUITE_RESELLER_TOKEN=
DROPSUITE_AUTH_TOKEN=

# After tokens work: set DROPSUITE_ENABLED=true, config:clear,
# php artisan portal:probe-security-apis --dropsuite-org=ORG_ID
# then map clients.dropsuite_organization_id (Admin → Clients).
# Access token must authorize GET /api/users or GET /api/accounts (reseller alone only satisfies GET /api/status).

PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
MICROSOFT_365_PORTAL_URL=https://admin.microsoft.com
KNOWLEDGE_BASE_URL=https://your-kb-url
BILLING_PORTAL_URL=https://your-billing-url

ENTRA_SYNC_ENABLED=true
ENTRA_SYNC_MAINTAIN_SUPEROPS_GROUP=true
ENTRA_SYNC_SUPEROPS_NAME_EXTENSION_ATTRIBUTE=1
```

See [EntraGroupSync.md](EntraGroupSync.md) for optional keys (`ENTRA_SYNC_CLIENT_ID`, provision-on-demand interval, etc.).

### 7. Run Migrations and Seeders

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan portal:purge-demo-data --force   # one-time cleanup for older installs
```

### 8. Set File Permissions

```bash
chown -R www-data:psacln storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 9. Optimise for Production

```bash
npm ci && npm run build   # if not built locally - uploads public/build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 10. Configure Cron / systemd timers

**Production note (app.onit.ltd on Plesk):** Plesk UI **Scheduled Tasks → Run a command** fails for `/opt/plesk/php/8.3/bin/php` with:

`Inconsistency detected by ld.so: … _dl_call_libc_early_init … Assertion 'sym != NULL' failed!`

That is Plesk’s jailed task runner, not Laravel. Do **not** rely on the Plesk UI for scheduler/queue on this host.

#### Preferred: systemd timers (live on app.onit.ltd)

On this host, minute **root cron sometimes skipped 5-10 minutes** while `cron` RSS ballooned (~900MB; healthy is a few MB). Many **orphan FTP crontabs** also spam reloads. Primary path is **systemd timers** (still use Plesk PHP binary, app path only):

| Unit | When | Command |
|---|---|---|
| `onit-portal-schedule.timer` | every minute at `:00` | `php artisan schedule:run` |
| `onit-portal-queue.timer` | every minute at `:00` | `queue:work … --max-time=55` |
| `onit-portal-queue-b.timer` | every minute at `:20` | second drain (parallel capacity) |

Files: `/etc/systemd/system/onit-portal-{schedule,queue,queue-b}.{service,timer}`. Enable with `systemctl enable --now onit-portal-schedule.timer onit-portal-queue.timer onit-portal-queue-b.timer`. Check: `systemctl list-timers 'onit-portal*'` and Integration Health **Scheduler** tick age.

If `cron` RSS is huge again: `systemctl restart cron` (safe; does not touch other vhosts). Clean orphan FTP crontabs separately under Plesk when someone has time - they thrash the cron daemon, not the portal app code.

#### Root crontab (belt-and-suspenders; keep alongside timers)

Use **absolute** log paths. Relative `>> storage/logs/...` only works if `cd` succeeds:

```
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan schedule:run >> /var/www/vhosts/onit.ltd/app.onit.ltd/storage/logs/scheduler.log 2>&1
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan queue:work database --queue=high,default --stop-when-empty --max-time=55 --sleep=1 --tries=3 >> /var/www/vhosts/onit.ltd/app.onit.ltd/storage/logs/queue-worker-1.log 2>&1
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan queue:work database --queue=high,default --stop-when-empty --max-time=55 --sleep=1 --tries=3 >> /var/www/vhosts/onit.ltd/app.onit.ltd/storage/logs/queue-worker-2.log 2>&1
```

**Do not use `onOneServer()`** for scheduled commands on this single host with `CACHE_STORE=database`. A stuck row in `cache_locks` can skip `portal:prewarm-client-dashboards` for tens of minutes (data ages with empty queue). Prewarm clears expired / absurdly long schedule locks on each run.

Hourly **`portal:sync-entra-users`** (Entra Extra Sync on Integration Health) only runs when `schedule:run` runs. Multi-day Entra ages usually mean the minute scheduler was dead - not SuperOps prewarm. Manual catch-up: `php artisan portal:sync-entra-users` then drain `queue:work … high,default`.

Nightly: **`portal:capture-metric-snapshots`** at 02:15; **`model:prune` ActivityLog** at 03:20 **`APP_TIMEZONE`** (keep `ACTIVITY_LOG_RETAIN_DAYS`, default 90). Do not skip localhost IPs - see [DatabaseSchema.md](DatabaseSchema.md).

Two concurrent workers so **one client's long M365/Entra job does not block every other client**. Laravel's database queue locks jobs; both workers are safe. Prefer Supervisor `numprocs=2` if available.

`--queue=high,default` runs SuperOps / M365 directory / M365 insights (`high`) **before** Entra/SCIM (`default`). **`--max-time=55`** so each minute worker exits before the next minute spawns another (do **not** use 300 with minute cron - that stacks overlapping workers). Long Entra jobs may span workers; that is intentional. The scheduler queues work; the workers process it.

### 11. Run the queue worker

Background work (M365 directory refresh, SuperOps dashboard metrics, admin Entra sync) uses Laravel's **database queue** - not `dispatch()->afterResponse()`. Jobs are written to the `jobs` table when `QUEUE_CONNECTION=database`.

**Required `.env`:**

```env
QUEUE_CONNECTION=database
```

Production must have both the `jobs` and `failed_jobs` tables. `jobs` ships in the base portal migration; if `failed_jobs` is missing, run migrations after deploying the `create_failed_jobs_table` migration (otherwise `queue:failed` / failed-job counts error with `Table '…failed_jobs' doesn't exist`).

PHPUnit sets `QUEUE_CONNECTION=sync` in `phpunit.xml` so tests still run jobs inline.

**Worker command (production):**

```bash
php artisan queue:work database --queue=high,default --sleep=1 --tries=3
```

Run this **continuously** - choose one:

| Option | When to use |
|---|---|
| **Supervisor** (recommended) | SSH/root access; keeps worker alive across restarts - set **`numprocs=2`** for parallel clients |
| **systemd timers** | Live on app.onit.ltd - `onit-portal-queue` / `onit-portal-queue-b` (see §10) |
| **Plesk / root crontab** | Two `queue:work --stop-when-empty` lines each minute (see §10) |

**Supervisor example** (`/etc/supervisor/conf.d/onit-portal-queue.conf`):

```ini
[program:onit-portal-queue]
process_name=%(program_name)s_%(process_num)02d
command=/opt/plesk/php/8.3/bin/php /var/www/vhosts/onit.ltd/app.onit.ltd/artisan queue:work database --queue=high,default --sleep=1 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/vhosts/onit.ltd/app.onit.ltd/storage/logs/queue-worker.log
```

Then: `supervisorctl reread && supervisorctl update && supervisorctl start onit-portal-queue:`

**Plesk / cron fallback** (two workers):

```
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan queue:work database --queue=high,default --stop-when-empty --max-time=300 --sleep=1 --tries=3 >> storage/logs/queue-worker-1.log 2>&1
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && /opt/plesk/php/8.3/bin/php artisan queue:work database --queue=high,default --stop-when-empty --max-time=300 --sleep=1 --tries=3 >> storage/logs/queue-worker-2.log 2>&1
```

`--queue=high,default` so dashboard/directory jobs win over Entra when both are pending.
Use the same Plesk PHP binary as in [Updating the Application](#updating-the-application). After deploy, restart Supervisor or wait for the next cron tick.

**Queued jobs:**

| Job | Queue | Trigger |
|---|---|---|
| `RefreshSuperOpsDashboardJob` | `high` | Prewarm / cold SuperOps cache / manual refresh |
| `RefreshM365DirectoryJob` | `high` | Prewarm / cold directory / manual refresh |
| `RefreshM365InsightsJob` | `high` | Prewarm / licence insights |
| `RefreshHuntressSecurityJob` | high | Stale Huntress security metrics |
| `RefreshDropsuiteBackupJob` | high | Stale Dropsuite / SaaS Backup metrics |
| `SyncEntraClientJob` | default | Admin → Clients → Sync Entra users |

### 12. SSL Certificate

1. Plesk → SSL/TLS Certificates
2. Install Let's Encrypt (free)
3. Enable "Redirect HTTP to HTTPS"

## Microsoft Entra ID App Registration

1. Go to [Azure Portal](https://portal.azure.com) → Microsoft Entra ID → App registrations
2. New registration:
   - Name: **OnIT Portal for Portals**
   - Supported account types: "Accounts in any organizational directory"
   - Redirect URI: Web → `https://app.onit.ltd/auth/microsoft/callback` (your Laravel subdomain - **not** `portal.onit.ltd`)
3. Note the Application (client) ID
4. Certificates & secrets → New client secret → note the value
5. API permissions → Add:
   - **Delegated:** `Microsoft Graph` → `openid`, `profile`, `email`, `User.Read` (portal login)
   - **Application:** `Microsoft Graph` → all **fourteen** Application permissions below (Entra sync + Connect bootstrap + Secure Score/MFA - full list in [CustomerEntraSyncRunbook.md](CustomerEntraSyncRunbook.md) Step 0):
     - `User.Read.All`
     - `User.ReadWrite.All`
     - `LicenseAssignment.Read.All`
     - `MailboxSettings.Read`
     - `Group.Read.All`
     - `Group.ReadWrite.All`
     - `GroupMember.ReadWrite.All`
     - `AppRoleAssignment.ReadWrite.All`
     - `Application.Read.All`
     - `Application.ReadWrite.All`
     - `Synchronization.ReadWrite.All`
     - `SecurityEvents.Read.All`
     - `AuditLog.Read.All`
     - `Reports.Read.All`
6. Grant admin consent in the On IT home tenant
7. Customer tenants: checklist orange **Connect Microsoft tenant** (or **re-consent** after adding permissions - never delete the client)

## Post-Deployment Verification

- [ ] Login page loads at `https://app.onit.ltd/login`
- [ ] Microsoft sign-in redirects and returns successfully
- [ ] Dashboard displays for authenticated user
- [ ] Admin area accessible for admin roles
- [ ] Dashboard **SuperOps** and **Pax8** tiles redirect via launch routes (same tab)
- [ ] Technician Pax8 → partner portal; client with `pax8_company_id` → company view
- [ ] `APP_DEBUG=false` - no stack traces on errors
- [ ] SSL certificate valid and HTTP redirects to HTTPS
- [ ] Queue worker running (`QUEUE_CONNECTION=database`; `jobs` table draining)

## Updating the Application

### Authoritative production layout (app.onit.ltd)

| Role | Path |
|---|---|
| GitHub remote | `git@github.com:T4shby/OnIT-Portal.git` |
| Plesk bare mirror | `/var/www/vhosts/onit.ltd/git/laravel_af3fd1` (repo name in Plesk: **laravel_af3fd1**) |
| Live Laravel tree | `/var/www/vhosts/onit.ltd/app.onit.ltd` - **no** `.git` |
| Deploy key / known_hosts | `/var/www/vhosts/onit.ltd/.ssh/id_rsa`, `…/git_known_hosts` |
| Site owner | `onit.ltd_1hfwweogk0mj:psaserv` (critical after extract) |
| Plesk Git DB | `/opt/psa/var/modules/git/git_db.db` (`Repositories.branch` must be **`main`**) |
| Deploy markers | Bare: `DEPLOYED_SHA` / `DEPLOYED_SHA_FULL`; live: `.deployed-commit` |

**Normal release path:** push `main` on GitHub → **Plesk UI Pull + Deploy**, *or* SSH pipeline below → then **post-deploy artisan block**.

**Ops diary:** every SSH/deploy session must append [ServerOpsLog.md](ServerOpsLog.md) (Cursor rule `server-ops-log.mdc`).

Do **not** `git pull` inside `app.onit.ltd` (there is no clone). Do **not** leave production tracking a feature branch (it was briefly on `feature/client-onboarding`, then **`UIOverhaul`** until **2026-08-18**). **`UIOverhaul` is merged to `main`** - deploy **`main`** only.

### SSH: pull GitHub + deploy via Plesk bare mirror

Use this when automating or when the Plesk UI is awkward. Matches what Plesk does: update the bare repo from GitHub, then export tracked files into the live path (`.env` stays untracked).

```bash
GIT_DIR=/var/www/vhosts/onit.ltd/git/laravel_af3fd1
APP=/var/www/vhosts/onit.ltd/app.onit.ltd
VHOST_USER=onit.ltd_1hfwweogk0mj
OWNER=onit.ltd_1hfwweogk0mj:psaserv
export HOME=/var/www/vhosts/onit.ltd
export GIT_SSH_COMMAND='ssh -i /var/www/vhosts/onit.ltd/.ssh/id_rsa -o UserKnownHostsFile=/var/www/vhosts/onit.ltd/.ssh/git_known_hosts -o StrictHostKeyChecking=yes'

# 1) Fetch all branches into the bare mirror (needs vhost deploy key on GitHub)
sudo -u "$VHOST_USER" env HOME="$HOME" GIT_SSH_COMMAND="$GIT_SSH_COMMAND" \
  git --git-dir="$GIT_DIR" fetch origin

git --git-dir="$GIT_DIR" rev-parse --short main   # confirm expected SHA

# 2) Export main into live site (does not overwrite .env)
sudo -u "$VHOST_USER" git --git-dir="$GIT_DIR" archive main | tar -x -C "$APP"
chown -R "$OWNER" "$APP/app" "$APP/Brain" "$APP/bootstrap" "$APP/config" \
  "$APP/database" "$APP/public" "$APP/resources" "$APP/routes" || true

# `git archive | tar -x` only adds/overwrites. Files **deleted in git stay on disk**.
# After a deploy that removes Blade/PHP/CSS, `rm` those paths on the live tree (2026-08-18:
# leftover `resources/views/layouts/{app,admin}.blade.php` and `_service-column.blade.php`).

# 3) Record deployed SHA
SHORT=$(git --git-dir="$GIT_DIR" rev-parse --short main)
FULL=$(git --git-dir="$GIT_DIR" rev-parse main)
echo "$SHORT" | tee "$GIT_DIR/DEPLOYED_SHA" "$APP/.deployed-commit"
echo "$FULL" > "$GIT_DIR/DEPLOYED_SHA_FULL"
chown "$OWNER" "$APP/.deployed-commit"

# 4) Keep Plesk UI in sync (branch + last deploy) - optional but recommended
sqlite3 /opt/psa/var/modules/git/git_db.db \
  "UPDATE Repositories SET branch='main' WHERE name='laravel_af3fd1';"
# Update RepositoryDeploymentInfo hashes/message from `git log -1 main` if you care about UI accuracy

# 5) Post-deploy artisan block (next section)
```

After **every** deploy, run **all** of the artisan steps below - not “just fetch”.

**Plesk PHP:** `php` is not on the root shell `PATH`. List installed versions and use the one set on the domain (Plesk → Domains → **app.onit.ltd** → **PHP Settings** - must be **8.2+** for Laravel 11):

```bash
ls /opt/plesk/php/*/bin/php
PHP=/opt/plesk/php/8.3/bin/php    # example - use 8.2 or 8.3, not 8.1
$PHP -v
```

### Post-deploy artisan block (always)

**Copy-paste block (run after every Plesk Git pull/deploy):**

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd

# php is NOT on root PATH - composer and artisan both need Plesk's PHP
export PATH="/opt/plesk/php/8.3/bin:$PATH"    # match Plesk PHP Settings for app.onit.ltd
export COMPOSER_ALLOW_SUPERUSER=1              # skip "Continue as root?" prompt on Plesk SSH
php -v

rm -f public/hot

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan route:clear
php artisan config:clear
php artisan view:clear

# Prefer NOT `php artisan cache:clear` on routine deploys - it wipes M365 directory
# snapshots (`m365_directory.client.*`) and Client Admin metric caches, forcing cold
# “synchronising” empty states until background jobs rebuild them.

php artisan db:seed --class=PortalLinkSeeder --force

php artisan optimize

# Artisan as root leaves files root-owned → PHP-FPM (vhost user) cannot write compiled views → 500 Permission denied
chown -R onit.ltd_1hfwweogk0mj:psaserv storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

> **`cache:clear`:** wipes application cache store (directory snapshots, SuperOps/M365 insights, Entra health flags). Only use when intentionally resetting caches; otherwise clear route/config/view only.
> **`/usr/bin/env: 'php': No such file or directory`** - caused by plain `composer install` without Plesk PHP on `PATH`. Use `export PATH=...` above, or `$PHP $(command -v composer) install ...`.

> **Why `route:clear` before `optimize`?** New routes (e.g. Team, Pax8 launch) are referenced in views. Stale route cache causes **500 on `/admin`** with `Route [...] not defined` in `storage/logs/laravel.log`.

> **Why `PortalLinkSeeder`?** Upserts dashboard links to `superops_sso` and `pax8_sso` launch routes. Safe to re-run.

> **Why `rm -f public/hot`?** A leftover Vite dev file makes production load CSS from `localhost:5173` - unstyled pages. See [CSS not loading](#css-not-loading-unstyled-html).

> **500 Permission denied on `storage/framework/views`:** Post-deploy `php artisan optimize` (or archive) ran as **root** and left `storage/` root-owned. Fix: `chown -R onit.ltd_1hfwweogk0mj:psaserv storage bootstrap/cache` (always run after artisan as root).

`php artisan portal:purge-demo-data --force` is safe to re-run on older installs; skip if you have already cleaned demo data.

### Verify deploy alignment

| Check | Expect |
|---|---|
| GitHub `main` | tip SHA (e.g. `bd15207`) |
| `git --git-dir=…/laravel_af3fd1 rev-parse --short main` | same SHA |
| `cat app.onit.ltd/.deployed-commit` | same short SHA |
| SuperOps GraphQL fix present | `normalizeJsonObject` in `SuperOpsClientMetricsService.php` |

### Pax8 first deploy

Add to production `.env` (see [Pax8Integration.md](Pax8Integration.md)). Technician Microsoft SSO: [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md).

```env
PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
```

Then run the deploy block above. Set **Pax8 company ID** per client in Admin → Clients → Edit.

## Troubleshooting

| Issue | Solution |
|---|---|
| **500 on `/admin` or `/dashboard`** | Check `storage/logs/laravel.log`. Common: **Permission denied** writing `storage/framework/views` after root `optimize` → `chown -R onit.ltd_1hfwweogk0mj:psaserv storage bootstrap/cache`. Or `Route [...] not defined` / missing migration. |
| 500 error (general) | Check `storage/logs/laravel.log`, verify permissions |
| Login redirect fails | Verify `MICROSOFT_REDIRECT_URI` matches Entra app registration exactly |
| Session not persisting / Socialite InvalidStateException | Verify `sessions` table exists (`php artisan tinker --execute="echo Schema::hasTable('sessions') ? 'yes' : 'no';"`), `SESSION_DRIVER=database`, and do not set `SESSION_DOMAIN=null` (leave blank or unset). ~~After deploy, set `MICROSOFT_OAUTH_STATELESS=true`~~ - **retired 2026-09-29 (eighth audit pass):** that variable no longer exists. Sign-in no longer keeps its OAuth state in the session, so `InvalidStateException` cannot occur. |
| Login error "did not send back the portal's sign-in cookie" / "did not match" / "took longer than 15 minutes" | Sign-in state check (`App\Support\MicrosoftOAuthState`, cookie `__Host-onit_oauth_state`). Start again at `/login` in one browser. Check `storage/logs/laravel.log` for `Microsoft OAuth state check failed`: its `reason` and `has_state_cookie` fields give the cause. `APP_URL` and `MICROSOFT_REDIRECT_URI` must share a host. See `docs/DEPLOYMENT.md`. |
| CSS not loading / unstyled page | See **CSS not loading** below |
| Permission denied | `chmod -R 775 storage bootstrap/cache` |
| M365 directory / SuperOps dashboard never updates | Confirm `QUEUE_CONNECTION=database` in `.env`, `jobs` table exists (`php artisan migrate`), and queue worker is running - see [Run the queue worker](#11-run-the-queue-worker) |
| cURL SSL error on **local Windows only** | Not a production issue - see [LocalDevelopment.md](LocalDevelopment.md#php-ssl-certificates-windows--required) |

### CSS not loading (unstyled HTML)

The portal loads CSS via Laravel Vite from `public/build/` (committed in git). A plain white page with blue links means those assets did not load.

**Browser asked to “allow” local network / localhost?** Almost always a stray **`public/hot`** file on the server - left over if someone ran `npm run dev` on production. That file tells Laravel to load CSS from `http://127.0.0.1:5173` instead of `public/build`. Remove it:

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
rm -f public/hot
php artisan view:clear
```

**Checklist (SSH):**

```bash
# 1. hot file must NOT exist
ls -la public/hot          # should say "No such file"

# 2. built assets must exist
ls -la public/build/manifest.json
ls -la public/build/assets/

# 3. APP_URL must be https (in .env)
grep APP_URL .env          # expect APP_URL=https://app.onit.ltd

$PHP artisan config:clear
$PHP artisan view:clear
$PHP artisan optimize
```

If `public/build` is missing or empty, build on the server (Node 18+ required):

```bash
npm ci && npm run build
$PHP artisan optimize
```

**Quick browser check:** View page source on the dashboard. You should see:

```html
<link rel="stylesheet" href="/build/assets/app-....css" />
```

If you see `app-XXXX.css` in `public/build/assets/` but page source references a **different** hash, `manifest.json` and the CSS file are out of sync. On a **git** checkout: `git checkout HEAD -- public/build/`. On **Plesk deploy** (no `.git`): run `npm ci && npm run build` in the app directory to regenerate both files, then `$PHP artisan view:clear && $PHP artisan optimize`.

Repo on GitHub may use a different hash (e.g. `app-BPNDoNek.css`) than the server (`app-CWxgn93d.css`) - that is fine **as long as `manifest.json` and the file in `assets/` match on the same machine**.

**Never run `npm run dev` on production** - local dev only ([LocalDevelopment.md](LocalDevelopment.md)).

## Local vs Production

| Concern | Local (Windows) | Production (Plesk) |
|---|---|---|
| Doc | [LocalDevelopment.md](LocalDevelopment.md) | This file |
| Database | SQLite | MySQL/MariaDB |
| HTTPS | Optional (`http://localhost:8000`) | Required (Let's Encrypt) |
| PHP CA certs | Manual `cacert.pem` in `php.ini` | OS-managed; no extra step |
| `APP_DEBUG` | `true` | `false` |
