# On IT Portal — Local Development (Windows)

Guide for developers running the portal on a Windows workstation. Production deployment is documented in [Deployment.md](Deployment.md).

## Project Location

**Use:** `C:\Dev\OnIT-Portal`

**Do not develop from OneDrive-synced folders** (e.g. Desktop, Documents under OneDrive). PHP/Laravel needs to write to `storage/` and `bootstrap/cache/`. OneDrive file locking causes permission errors and failed cache writes.

If you clone fresh:

```powershell
git clone <repo-url> C:\Dev\OnIT-Portal
cd C:\Dev\OnIT-Portal
```

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2+ | Install via WinGet: `winget install PHP.PHP.8.2` |
| Composer | 2.x | https://getcomposer.org |
| Node.js | 18+ | For `npm run build` if you change frontend assets |
| Git | Latest | |

Verify:

```powershell
php -v
composer -V
```

## First-Time Setup

```powershell
cd C:\Dev\OnIT-Portal

composer install
copy .env.example .env
php artisan key:generate
```

### Database (SQLite — recommended for local)

SQLite avoids installing MySQL locally. Edit `.env`:

```env
DB_CONNECTION=sqlite
# Comment out or remove DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

Create the database file and migrate:

```powershell
New-Item -ItemType File -Path database\database.sqlite -Force
php artisan migrate
php artisan db:seed
```

### Application URL

Use **localhost**, not `127.0.0.1`, unless both are registered in Entra ID:

```env
APP_URL=http://localhost:8000
MICROSOFT_REDIRECT_URI=http://localhost:8000/auth/microsoft/callback
```

`APP_URL` and `MICROSOFT_REDIRECT_URI` must match what is registered in Azure and what you type in the browser.

### Microsoft Entra ID (local)

In [Azure Portal](https://portal.azure.com) → App registrations → your app:

1. **Authentication** → Add platform **Web**
2. Redirect URI: `http://localhost:8000/auth/microsoft/callback`
3. Copy **Application (client) ID** and a **client secret** into `.env`:

```env
MICROSOFT_CLIENT_ID=your-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=organizations
```

### Super admin bootstrap

Set your work email so the seeder creates your admin account:

```env
SUPER_ADMIN_EMAIL=you@onit.ltd
```

After seeding, sign in with Microsoft — first login binds your Entra object ID to the seeded user.

### SuperOps (optional locally)

**To open the SuperOps client portal from the dashboard**, set at least your subdomain:

```env
SUPEROPS_SUBDOMAIN=onitltd
SUPEROPS_REQUESTER_PORTAL_URL=https://portal.onit.ltd
SUPEROPS_REQUESTER_LOGIN_PATH=/#/requester/login
SUPEROPS_LOGIN_HINT_ENABLED=true
SUPEROPS_SSO_ENABLED=true
```

Technicians (`super_admin`, `account_manager`) see the SuperOps card when `SUPEROPS_PORTAL_URL` is set. Client users need `SUPEROPS_SUBDOMAIN` or `SUPEROPS_REQUESTER_PORTAL_URL` plus `superops_sso_enabled` on their client.

## PHP SSL Certificates (Windows — required)

### Symptom

Sign-in reaches Microsoft, then fails on callback with:

```
Authentication failed: cURL error 60: SSL certificate OpenSSL verify result:
unable to get local issuer certificate (20) for https://login.microsoftonline.com/.../token
```

This happens because WinGet PHP ships without a CA bundle configured in `php.ini`.

### Why "Mozilla" CA bundle? (Not your browser)

This has **nothing to do with Firefox, Edge, or Chrome**.

| Component | Who verifies HTTPS? | Where trust comes from |
|---|---|---|
| Your browser (Edge, Chrome, Firefox) | The browser | Windows certificate store and/or the browser's own roots |
| Laravel / PHP / cURL (server-side) | PHP when exchanging the OAuth token | `php.ini` → `curl.cainfo` / `openssl.cafile` |

When you click "Sign in with Microsoft", the **browser** talks to Microsoft fine — that's why you see the Microsoft login page. After you sign in, **PHP** makes a background HTTPS request to `login.microsoftonline.com` to swap the auth code for tokens. That request is made by cURL inside PHP, not by your browser.

PHP does not use Edge's or Chrome's certificate store. It needs its own list of trusted Certificate Authorities (CAs). The file at [curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem) is a standard bundle of public root CA certificates maintained by Mozilla for the curl project. The name is historical — it is used by PHP, curl, Git, and many tools on all platforms, regardless of which browser anyone uses.

On Ubuntu/Plesk production, the OS usually provides this automatically. WinGet PHP on Windows often does not, so we configure it manually.

### Fix (one-time per PHP install)

1. Find your `php.ini`:

   ```powershell
   php --ini
   ```

2. Download the Mozilla CA bundle next to `php.ini`:

   ```powershell
   $phpDir = Split-Path (php -r "echo php_ini_loaded_file();")
   Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$phpDir\cacert.pem"
   ```

3. Edit `php.ini` — uncomment and set both directives (use your actual path):

   ```ini
   [curl]
   curl.cainfo = "C:\path\to\php\cacert.pem"

   [openssl]
   openssl.cafile="C:\path\to\php\cacert.pem"
   ```

4. Verify:

   ```powershell
   php -r "echo ini_get('curl.cainfo');"
   ```

5. **Restart** `php artisan serve` (PHP reads `php.ini` only at process start).

### Verify HTTPS works

```powershell
php -r "$c=curl_init('https://login.microsoftonline.com'); curl_setopt($c,CURLOPT_NOBODY,true); curl_exec($c); echo curl_error($c)?:'OK';"
```

Expected output: `OK`

**Never disable SSL verification** (`CURLOPT_SSL_VERIFYPEER=false`) in application code. Fix the CA bundle instead.

### After PHP updates

WinGet PHP upgrades may reset or replace `php.ini`. Re-check `curl.cainfo` and `openssl.cafile` after any PHP version change.

## Running the Dev Server

```powershell
cd C:\Dev\OnIT-Portal
php artisan serve --host=localhost --port=8000
```

Open: http://localhost:8000/login

If you change `.env`, run:

```powershell
php artisan config:clear
```

The dev server auto-restarts on `.env` changes, but **not** on `php.ini` changes — restart manually.

## Common Local Errors

| Error | Cause | Fix |
|---|---|---|
| `cURL error 60` / unable to get local issuer certificate | Missing CA bundle in `php.ini` | See [PHP SSL Certificates](#php-ssl-certificates-windows--required) |
| `AADSTS50011` redirect URI mismatch | Entra redirect URI ≠ `MICROSOFT_REDIRECT_URI` | Add exact URI in Azure; use `localhost` consistently |
| Empty `client_id` in Microsoft login URL | Missing `.env` credentials | Set `MICROSOFT_CLIENT_ID` and `MICROSOFT_CLIENT_SECRET`; `config:clear` |
| "Account has not been set up" | User not in database | Admin must create user, or set `SUPER_ADMIN_EMAIL` and re-seed |
| `bootstrap/cache` permission denied | Project in OneDrive or read-only path | Move to `C:\Dev\OnIT-Portal` |
| MySQL connection refused | MySQL not running | Switch to SQLite (see above) or start local MySQL |
| `no such column: remember_token` on login callback | Users migration predates remember-me column | Run `php artisan migrate` (see `2026_06_12_130600_add_remember_token_to_users_table.php`) |
| Generic "Authentication failed" | Token exchange or secret issue | Set `APP_DEBUG=true`; check `storage/logs/laravel.log` |
| Client secret expired | Secret rotated in Azure | Create new secret in Entra; update `.env` |

## Useful Commands

```powershell
php artisan migrate              # Apply migrations
php artisan db:seed              # Seed roles, super admin, portal links
php artisan db:seed --class=PortalLinkSeeder
php artisan config:clear         # After .env changes
php artisan route:list           # Inspect routes
php artisan tinker               # REPL for debugging
```

## What Differs from Production

| Setting | Local | Production |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `APP_URL` | `http://localhost:8000` | `https://app.onit.ltd` (Laravel app — not `portal.onit.ltd`) |
| Database | SQLite | MySQL/MariaDB |
| `SESSION_SECURE_COOKIE` | `false` (HTTP) | `true` (HTTPS) |
| PHP CA bundle | Manual on Windows | OS-managed on Ubuntu/Plesk |
| SSL | Not required | Let's Encrypt via Plesk |

## Related Docs

- [Authentication.md](Authentication.md) — login flow and Entra configuration
- [Deployment.md](Deployment.md) — Plesk production setup
- [README.md](README.md) — Brain index and change protocol
