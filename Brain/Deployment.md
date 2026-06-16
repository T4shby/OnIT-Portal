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
SUPEROPS_REGION=us
SUPEROPS_PORTAL_URL=https://app.superops.ai
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_REQUESTER_LOGIN_PATH="/#/requester/login"
SUPEROPS_LOGIN_HINT_ENABLED=true
SUPEROPS_SSO_ENABLED=true
SUPEROPS_AUTO_OPEN_AFTER_LOGIN=false

PAX8_PORTAL_URL=https://your-pax8-url
MICROSOFT_365_PORTAL_URL=https://admin.microsoft.com
KNOWLEDGE_BASE_URL=https://your-kb-url
BILLING_PORTAL_URL=https://your-billing-url
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
5. API permissions → Add: `Microsoft Graph` → Delegated → `openid`, `profile`, `email`, `User.Read`
6. Grant admin consent if required

## Post-Deployment Verification

- [ ] Login page loads at `https://app.onit.ltd/login`
- [ ] Microsoft sign-in redirects and returns successfully
- [ ] Dashboard displays for authenticated user
- [ ] Admin area accessible for admin roles
- [ ] Service cards launch external URLs in new tabs
- [ ] `APP_DEBUG=false` — no stack traces on errors
- [ ] SSL certificate valid and HTTP redirects to HTTPS

## Updating the Application

```bash
cd /var/www/vhosts/onit.ltd/app.onit.ltd
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan portal:purge-demo-data --force   # safe to re-run; no-op if already clean
php artisan optimize
```

## Troubleshooting

| Issue | Solution |
|---|---|
| 500 error | Check `storage/logs/laravel.log`, verify permissions |
| Login redirect fails | Verify `MICROSOFT_REDIRECT_URI` matches Entra app registration exactly |
| Session not persisting / Socialite InvalidStateException | Verify `sessions` table exists, `SESSION_DRIVER=database`, and do not set `SESSION_DOMAIN=null` (leave blank or unset) |
| CSS not loading | Run `npm run build` if assets changed, verify `public/build` exists |
| Permission denied | `chmod -R 775 storage bootstrap/cache` |
| cURL SSL error on **local Windows only** | Not a production issue — see [LocalDevelopment.md](LocalDevelopment.md#php-ssl-certificates-windows--required) |

## Local vs Production

| Concern | Local (Windows) | Production (Plesk) |
|---|---|---|
| Doc | [LocalDevelopment.md](LocalDevelopment.md) | This file |
| Database | SQLite | MySQL/MariaDB |
| HTTPS | Optional (`http://localhost:8000`) | Required (Let's Encrypt) |
| PHP CA certs | Manual `cacert.pem` in `php.ini` | OS-managed; no extra step |
| `APP_DEBUG` | `true` | `false` |
