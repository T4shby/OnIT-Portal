# On IT Portal — Deployment

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

> **On IT production URL:** `https://app.onit.ltd` — the Laravel On IT Portal.  
> **`portal.onit.ltd`** is the SuperOps requester portal (CNAME to SuperOps) — do not host Laravel there.

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

**Option A: Git (recommended)**

1. Enable Git extension in Plesk
2. Clone repository to `/app.onit.ltd`
3. Set deployment branch to `main`

**Option B: SFTP**

1. Upload all files to `/app.onit.ltd` via SFTP
2. Ensure `.env` is configured on server (never commit `.env`)

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

PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
MICROSOFT_365_PORTAL_URL=https://admin.microsoft.com
KNOWLEDGE_BASE_URL=https://your-kb-url
BILLING_PORTAL_URL=https://your-billing-url

ENTRA_SYNC_ENABLED=true
```

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
npm ci && npm run build   # if not built locally — uploads public/build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 10. Configure Cron

In Plesk → Scheduled Tasks, add:

```
* * * * * cd /var/www/vhosts/onit.ltd/app.onit.ltd && php artisan schedule:run >> /dev/null 2>&1
```

### 11. SSL Certificate

1. Plesk → SSL/TLS Certificates
2. Install Let's Encrypt (free)
3. Enable "Redirect HTTP to HTTPS"

## Microsoft Entra ID App Registration

1. Go to [Azure Portal](https://portal.azure.com) → Microsoft Entra ID → App registrations
2. New registration:
   - Name: "On IT Portal"
   - Supported account types: "Accounts in any organizational directory"
   - Redirect URI: Web → `https://app.onit.ltd/auth/microsoft/callback` (your Laravel subdomain — **not** `portal.onit.ltd`)
3. Note the Application (client) ID
4. Certificates & secrets → New client secret → note the value
5. API permissions → Add:
   - **Delegated:** `Microsoft Graph` → `openid`, `profile`, `email`, `User.Read` (login — unchanged)
   - **Application:** `Microsoft Graph` → `GroupMember.Read.All`, `User.Read.All` (Entra group sync — [EntraGroupSync.md](EntraGroupSync.md))
6. Grant admin consent in the On IT home tenant
7. Grant admin consent in **each customer tenant** where you sync (consent URL in [EntraGroupSync.md](EntraGroupSync.md))

## Post-Deployment Verification

- [ ] Login page loads at `https://app.onit.ltd/login`
- [ ] Microsoft sign-in redirects and returns successfully
- [ ] Dashboard displays for authenticated user
- [ ] Admin area accessible for admin roles
- [ ] Dashboard **SuperOps** and **Pax8** tiles redirect via launch routes (same tab)
- [ ] Technician Pax8 → partner portal; client with `pax8_company_id` → company view
- [ ] `APP_DEBUG=false` — no stack traces on errors
- [ ] SSL certificate valid and HTTP redirects to HTTPS

## Updating the Application

After **every** deploy (Plesk Git pull or manual), run **all** of these over SSH — not just `git pull`.

**Plesk PHP:** `php` is not on the root shell `PATH`. List installed versions and use the one set on the domain (Plesk → Domains → **app.onit.ltd** → **PHP Settings** — must be **8.2+** for Laravel 11):

```bash
ls /opt/plesk/php/*/bin/php
PHP=/opt/plesk/php/8.3/bin/php    # example — use 8.2 or 8.3, not 8.1
$PHP -v
```

**Plesk Git deploy:** The live site path (`/var/www/vhosts/onit.ltd/app.onit.ltd`) often has **no `.git` folder** — Plesk copies files from a separate clone. Use **Plesk → Git → Pull/Deploy** for code updates; SSH `git pull` only works if `.git` exists in that directory.

**Copy-paste block (run after every Plesk Git pull/deploy):**

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd

# php is NOT on root PATH — composer and artisan both need Plesk's PHP
export PATH="/opt/plesk/php/8.3/bin:$PATH"    # match Plesk PHP Settings for app.onit.ltd
export COMPOSER_ALLOW_SUPERUSER=1              # skip "Continue as root?" prompt on Plesk SSH
php -v

rm -f public/hot

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan route:clear
php artisan config:clear
php artisan view:clear

php artisan db:seed --class=PortalLinkSeeder --force

php artisan optimize
```

> **`/usr/bin/env: 'php': No such file or directory`** — caused by plain `composer install` without Plesk PHP on `PATH`. Use `export PATH=...` above, or `$PHP $(command -v composer) install ...`.

> **Why `route:clear` before `optimize`?** New routes (e.g. Team, Pax8 launch) are referenced in views. Stale route cache causes **500 on `/admin`** with `Route [...] not defined` in `storage/logs/laravel.log`.

> **Why `PortalLinkSeeder`?** Upserts dashboard links to `superops_sso` and `pax8_sso` launch routes. Safe to re-run.

> **Why `rm -f public/hot`?** A leftover Vite dev file makes production load CSS from `localhost:5173` — unstyled pages. See [CSS not loading](#css-not-loading-unstyled-html).

If `.git` exists in the app directory you can `git pull origin main` before the block; on typical Plesk deploys use **Plesk → Git → Pull/Deploy** instead (no `.git` in the live path).

`php artisan portal:purge-demo-data --force` is safe to re-run on older installs; skip if you have already cleaned demo data.

### Pax8 first deploy

Add to production `.env` (see [Pax8Integration.md](Pax8Integration.md)):

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
| **500 on `/admin` after deploy** | Run the full update block above. Then: `tail -50 storage/logs/laravel.log` — look for `Route [...] not defined`, missing class, or SQL "column not found" (run `php artisan migrate --force`) |
| 500 error (general) | Check `storage/logs/laravel.log`, verify permissions |
| Login redirect fails | Verify `MICROSOFT_REDIRECT_URI` matches Entra app registration exactly |
| Session not persisting / Socialite InvalidStateException | Verify `sessions` table exists, `SESSION_DRIVER=database`, and do not set `SESSION_DOMAIN=null` (leave blank or unset) |
| CSS not loading / unstyled page | See **CSS not loading** below |
| Permission denied | `chmod -R 775 storage bootstrap/cache` |
| cURL SSL error on **local Windows only** | Not a production issue — see [LocalDevelopment.md](LocalDevelopment.md#php-ssl-certificates-windows--required) |

### CSS not loading (unstyled HTML)

The portal loads CSS via Laravel Vite from `public/build/` (committed in git). A plain white page with blue links means those assets did not load.

**Browser asked to “allow” local network / localhost?** Almost always a stray **`public/hot`** file on the server — left over if someone ran `npm run dev` on production. That file tells Laravel to load CSS from `http://127.0.0.1:5173` instead of `public/build`. Remove it:

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

Repo on GitHub may use a different hash (e.g. `app-BPNDoNek.css`) than the server (`app-CWxgn93d.css`) — that is fine **as long as `manifest.json` and the file in `assets/` match on the same machine**.

**Never run `npm run dev` on production** — local dev only ([LocalDevelopment.md](LocalDevelopment.md)).

## Local vs Production

| Concern | Local (Windows) | Production (Plesk) |
|---|---|---|
| Doc | [LocalDevelopment.md](LocalDevelopment.md) | This file |
| Database | SQLite | MySQL/MariaDB |
| HTTPS | Optional (`http://localhost:8000`) | Required (Let's Encrypt) |
| PHP CA certs | Manual `cacert.pem` in `php.ini` | OS-managed; no extra step |
| `APP_DEBUG` | `true` | `false` |
