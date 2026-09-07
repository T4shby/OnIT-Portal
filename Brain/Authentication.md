# On IT Portal - Authentication

## Strategy

Authentication is handled exclusively through **Microsoft Entra ID** using OpenID Connect (OIDC) / OAuth 2.0. No local passwords are stored or used.

## App Registration

This is **app #1** (OAuth) for the On IT Portal only. SuperOps requester SSO uses a **separate SAML enterprise app** - see [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md).

| Setting | Value |
|---|---|
| Supported account types | Multitenant - any organisational directory |
| Authority | `https://login.microsoftonline.com/organizations` |
| Redirect URI | `https://{domain}/auth/microsoft/callback` |
| API permissions | `openid`, `profile`, `email`, `User.Read` |
| Client secret | Stored in `.env` as `MICROSOFT_CLIENT_SECRET` |

## Environment Variables

```env
MICROSOFT_CLIENT_ID=your-app-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=organizations
MICROSOFT_REDIRECT_URI=https://app.onit.ltd/auth/microsoft/callback
```

`MICROSOFT_TENANT_ID=organizations` restricts sign-in to work/school accounts (no personal Microsoft accounts).

## Implementation

- **Package**: `laravel/socialite` + `socialiteproviders/microsoft-azure`
- **Controller**: `App\Http\Controllers\Auth\MicrosoftAuthController`
- **Routes**:
  - `GET /login` → redirect to Microsoft
  - `GET /auth/microsoft/callback` → handle OIDC callback
  - `POST /logout` → destroy session

## Login Flow

```
1. User visits /login
2. Application redirects to Microsoft Entra ID (organizations endpoint)
3. User authenticates with their work/school Microsoft account (+ MFA if configured)
4. Microsoft redirects to /auth/microsoft/callback with authorization code
5. Application exchanges code for tokens via Socialite
6. Application extracts: object ID, email, display name
7. Application looks up user by entra_object_id (prefer active, recent), then by email
8. If user found AND is_active:
   a. Update entra_object_id, name, last_login_at, encrypted microsoft_tokens; refresh **email** to Microsoft primary when free
   b. SuperOpsUserSyncService links requester by email (if API configured)
   c. SuperOpsSsoService establishes SSO session flag
   d. Create Laravel session (database-backed, remember-me cookie via `remember_token`)
   e. Log activity: `user.login` (IP from `ActivityLogService` - prefer forwarded client IP on Plesk; never skip the row because IP is localhost)
   f. Redirect to /dashboard (or /support if SUPEROPS_AUTO_OPEN_AFTER_LOGIN)
9. If user NOT found OR inactive:
   a. Redirect to /login with error message
```

## User Provisioning

**No self-registration.** Users must be created by an administrator before they can log in.

Users are provisioned by **Entra sync** (preferred) or manually. Access is not self-serve.

On login:
- Prefer match on `entra_object_id` (stable across primary-email changes - [DomainEmailChange.md](DomainEmailChange.md))
- Else match email from Entra against `users.email`
- Refresh primary email on the matched row when unique
- If neither matches, access is denied

Bootstrap super admin:
- Created via `UserSeeder` with a known email
- First Entra login binds the `entra_object_id`

## Session Configuration

```env
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true  # production with HTTPS
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Sessions table created via `php artisan session:table` migration.

## Rate Limiting

Auth callback route throttled to 6 attempts per minute per IP to prevent brute-force callback abuse.

## Security Considerations

- Never store Microsoft passwords
- Client secret rotated via Azure portal; update `.env` accordingly
- `entra_object_id` is immutable per user per tenant
- Session fixation prevented by Laravel's session regeneration on login
- Logout invalidates session and regenerates CSRF token

## Local Development

See [LocalDevelopment.md](LocalDevelopment.md) for full Windows setup.

Summary:

- Project path: `C:\Dev\OnIT-Portal` (not OneDrive)
- Use `http://localhost:8000` (not `127.0.0.1`) unless both are registered in Entra
- Redirect URI must match exactly: `http://localhost:8000/auth/microsoft/callback`
- Register under **Web** platform (not SPA)
- Windows PHP requires CA bundle in `php.ini` for token exchange (see LocalDevelopment.md)

## Error Handling

| Scenario | User Experience | Resolution |
|---|---|---|
| User not provisioned | "Your account has not been set up. Please contact your administrator." | Admin creates user record before first login |
| User inactive | "Your account has been deactivated. Please contact your administrator." | Admin reactivates user |
| Redirect URI mismatch (AADSTS50011) | Microsoft error page before callback | Entra redirect URI must match `MICROSOFT_REDIRECT_URI` exactly |
| cURL error 60 / SSL certificate (local Windows) | "Authentication failed: cURL error 60… unable to get local issuer certificate" | Configure `curl.cainfo` and `openssl.cafile` in `php.ini` - [LocalDevelopment.md](LocalDevelopment.md) |
| Microsoft auth failure | Detailed message when `APP_DEBUG=true` | Check `storage/logs/laravel.log` |
| Token exchange failure | Generic or detailed auth failed message | Verify client secret not expired; verify SSL/CA on Windows |
| Empty client_id in OAuth URL | Broken Microsoft login redirect | Set `MICROSOFT_CLIENT_ID` in `.env`; run `php artisan config:clear` |
