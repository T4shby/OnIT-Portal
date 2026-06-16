# On IT Portal

A customer-facing MSP platform providing a unified branded experience across multiple business systems. Built with Laravel 11, Blade, Tailwind CSS, and Microsoft Entra ID authentication.

## Features

- Microsoft Entra ID multi-tenant authentication (OIDC/OAuth2)
- SuperOps embedded support + SSO launch
- Client dashboard with service cards (Support, Pax8, M365, KB, Billing)
- Notices, recommendations, and opportunities per client
- Full admin area for content and user management
- Strict multi-tenant data isolation
- Activity logging for audit trail

## Requirements

- PHP 8.2+ (with extensions: openssl, zip, pdo_mysql, mbstring, curl, fileinfo)
- Composer
- Node.js 18+ and npm
- MySQL 8.0+ or MariaDB 10.6+

## Windows Setup Notes

PHP was not on PATH initially. Install via winget:

```powershell
winget install PHP.PHP.8.2
```

After install, copy `php.ini-development` to `php.ini` in the PHP directory and enable:

```ini
extension=openssl
extension=zip
extension=pdo_mysql
extension=mbstring
extension=curl
extension=fileinfo
```

Install Composer:

```powershell
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=<php-directory> --filename=composer.phar
```

**OneDrive projects:** PHP cannot write to `bootstrap/cache` inside OneDrive-synced folders. Use a junction to local AppData:

```powershell
mklink /J bootstrap\cache %LOCALAPPDATA%\onit-portal\bootstrap-cache
```

Restart your terminal after installing PHP/Node so PATH updates take effect.

## Local Development

```bash
# Install PHP dependencies
composer install

# Install frontend dependencies
npm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Edit .env with database and Microsoft Entra ID credentials

# Run migrations and seeders
php artisan migrate --seed

# Build frontend assets
npm run build

# Start development server
php artisan serve
```

For frontend hot-reload during development:

```bash
npm run dev
```

## Microsoft Entra ID Setup

1. Register a multi-tenant application in [Azure Portal](https://portal.azure.com)
2. Set redirect URI: `{APP_URL}/auth/microsoft/callback`
3. Add API permissions: `openid`, `profile`, `email`, `User.Read`
4. Copy Client ID and Client Secret to `.env`

```env
MICROSOFT_CLIENT_ID=your-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=organizations
MICROSOFT_REDIRECT_URI=http://localhost:8000/auth/microsoft/callback
```

## User Roles

| Role | Description |
|---|---|
| `super_admin` | Full platform access |
| `account_manager` | Admin for assigned clients |
| `client_admin` | Client organisation admin |
| `client_user` | Standard client user |

Users must be pre-provisioned by an administrator before they can log in.

## Default Seeded Data

- On IT internal client: `On IT Technology Partners`
- Portal SSO test user: `portal.test@onit.ltd`
- Super admin: email from `SUPER_ADMIN_EMAIL` env var
- Portal links from environment variables

To remove legacy demo records from older environments:

```bash
php artisan portal:purge-demo-data --force
```

## Project Documentation

**Start at [`Brain/README.md`](Brain/README.md)** — source of truth for all design and implementation.

SuperOps: [`Brain/SuperOpsIntegration.md`](Brain/SuperOpsIntegration.md)

## Production Deployment

See [Brain/Deployment.md](Brain/Deployment.md) for Plesk-specific deployment instructions.

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

## Tech Stack

- Laravel 11 (PHP monolith)
- Blade templates + Tailwind CSS + Alpine.js
- Laravel Socialite + Microsoft Azure provider
- MySQL/MariaDB with database-backed sessions

## License

Proprietary — On IT Technology Partners LTD
