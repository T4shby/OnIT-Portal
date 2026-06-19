# On IT Portal — Pax8 Integration

**Status:** Implemented (SSO launch route). Pax8 **API** automation remains Phase 3 ([Roadmap.md](Roadmap.md)).

## User experience

No new dashboard or page. The existing **Pax8 tile** on `/dashboard` works like SuperOps:

1. User clicks **Pax8** on the dashboard.
2. Browser goes to `GET /integrations/pax8/launch` (same tab).
3. Portal redirects to Pax8 with `login_hint={email}`.

| User type | Portal role | Pax8 destination |
|---|---|---|
| On IT technicians | `super_admin`, `account_manager` | Partner login (`PAX8_PARTNER_PORTAL_URL` + `PAX8_PARTNER_LOGIN_PATH`) |
| Customer users | `client_admin`, `client_user` | Company view (`PAX8_COMPANY_URL_TEMPLATE` + `client.pax8_company_id`) |

If a client user's organisation has no **Pax8 company ID** set, the Pax8 tile is hidden on the dashboard. A direct hit to the launch URL redirects back with an error flash.

## Architecture

```
Microsoft Entra → On IT Portal session
                  └── GET /integrations/pax8/launch
                        ├── Team member → partner login URL + login_hint
                        └── Client user  → company URL + login_hint
```

Mirrors [SuperOpsIntegration.md](SuperOpsIntegration.md) Pillar 2.

### Code map

| Piece | Path |
|---|---|
| Link type | `PortalLinkType::Pax8Sso` (`pax8_sso`) |
| Service | `app/Services/Pax8/Pax8SsoService.php` |
| Controller | `app/Http/Controllers/Integrations/Pax8LaunchController.php` |
| Route | `routes/web.php` → `integrations.pax8.launch` |
| Client field | `clients.pax8_company_id` |
| Admin forms | `admin/clients/create`, `admin/clients/edit` |
| Dashboard tile | `resources/views/components/service-card.blade.php` |
| Tests | `tests/Feature/Pax8LaunchTest.php`, `tests/Unit/Pax8SsoServiceTest.php` |

See [PortalLinks.md](PortalLinks.md) for link resolution and seeder defaults.

## Environment

```env
PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
```

`PAX8_PORTAL_URL` is legacy fallback for `PAX8_PARTNER_PORTAL_URL` in `config/services.php`.

Technicians launch to **`https://app.pax8.com/login?login_hint=…`** (not the bare root). Pax8's SPA then redirects to Auth0 at `login.pax8.com`.

After deploy, run `php artisan db:seed --class=PortalLinkSeeder --force` so the dashboard Pax8 link uses `pax8_sso` (not a static external URL).

## Pax8 platform constraints

### `login_hint` is not SSO

The portal passes `login_hint` so Pax8/Auth0 pre-fills the user's email on the identifier screen (`login.pax8.com/u/login/identifier`). **This does not complete authentication or trigger Microsoft SSO by itself.**

Unlike SuperOps requester SAML (portal → Microsoft → SuperOps in one hop), Pax8 uses **Auth0 Universal Login** with optional **Enterprise SSO (Azure AD)** configured inside Pax8. The portal cannot bypass the Auth0 identifier step or force an Azure redirect without Pax8-side Enterprise SSO being enabled.

### Partner Enterprise SSO (technicians)

- Pax8 → **Admin → My Partner Profile → Enterprise SSO → Azure AD** (Primary Partner Admin + Global Admin consents).
- Technicians must exist as **app users** in Pax8 with the **same UPN/email** as their Microsoft account.
- After setup: identifier screen (email pre-filled) → **Continue** → Microsoft consent/login → Pax8 dashboard.
- **Do not** use `mycommandconsole.com` or other custom Pax8 URLs for SSO — Pax8 docs require `https://app.pax8.com`. [Enterprise SSO PDF](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf)

### Customer SSO

Pax8 docs — self-service customer IdP SSO is **not** available yet. Customer launch relies on `login_hint` + Microsoft session and per-company URL.

### API

OAuth 2.0 ([devx.pax8.com](https://devx.pax8.com/docs/authentication)) — Phase 3 only. The `login.pax8.com/authorize` endpoint is for **third-party OAuth integrations**, not portal SSO launch.

## Operator setup (one-time + per client)

### P1. Pax8 Enterprise SSO (required for technician Microsoft SSO)

1. Sign in to Pax8 as **Primary Partner Admin**.
2. **Admin → My Partner Profile → Enterprise SSO → Azure AD**.
3. Enter **Email Domain** (On IT primary Azure domain, e.g. `onit.ltd`) and any **Domain Aliases**.
4. **Create** → add DNS TXT record → **Verify Domain** → **Finalize**.
5. Ensure each technician is a **Pax8 app user** with UPN matching portal login (e.g. `tom.ashby@onit.ltd`).
6. First login after setup: Global Admin accepts Pax8 consent at Microsoft.

### P2. Portal `.env` (production)

```env
PAX8_SSO_ENABLED=true
PAX8_PARTNER_PORTAL_URL=https://app.pax8.com
PAX8_PARTNER_LOGIN_PATH=/login
PAX8_COMPANY_URL_TEMPLATE=https://app.pax8.com/companies/{companyId}
PAX8_LOGIN_HINT_ENABLED=true
```

### P3. Per customer

Set **Pax8 company ID** in **Admin → Clients → Edit** (from Pax8 → Companies).

### P4. Validate technician launch

In a private/incognito window (no existing Pax8 session):

1. Portal → dashboard → **Pax8**.
2. Expect redirect to `https://app.pax8.com/login?login_hint=…` then Auth0 identifier with email pre-filled.
3. Click **Continue** → Microsoft login (if Enterprise SSO configured) → Pax8 partner dashboard.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Lands on `login.pax8.com/u/login/identifier` with email pre-filled, stops there | Expected before Enterprise SSO; or SSO not configured | Complete **P1** above; click **Continue** to trigger Azure redirect |
| Identifier page, no Microsoft redirect after Continue | Enterprise SSO not finalized, wrong email domain, or user not a Pax8 app user | Re-check Pax8 Enterprise SSO domains; create matching app user |
| Pax8 password/MFA prompt instead of Microsoft | Enterprise SSO not active for that domain | Use `app.pax8.com` (not `mycommandconsole.com`); verify SSO status in Pax8 admin |
| `invalid_request` hitting `login.pax8.com` directly | Auth0 requires SPA-initiated flow | Portal must launch via `app.pax8.com/login`, not `login.pax8.com` |
| Client user — Pax8 tile missing | No `pax8_company_id` on client | Set company ID in Admin → Clients → Edit |

## Testing checklist

- [ ] Technician → dashboard → Pax8 → `app.pax8.com/login` → Auth0 → Microsoft (after Enterprise SSO)
- [ ] Client approver with `pax8_company_id` → company subscriptions view
- [ ] Client without `pax8_company_id` → Pax8 tile hidden; direct launch shows error
- [ ] `php artisan test --filter=Pax8` passes

## Production deploy

See [Deployment.md — Updating the Application](Deployment.md#updating-the-application) for the full SSH block after Plesk Git pull.

## Related

- [SuperOpsIntegration.md](SuperOpsIntegration.md) — launch pattern
- [AccessAndSync.md](AccessAndSync.md) — identity model
- [OperatorRunbook.md](OperatorRunbook.md) — day-to-day ops
