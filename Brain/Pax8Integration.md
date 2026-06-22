# On IT Portal — Pax8 Integration

**Status:** Implemented (SSO launch route). Pax8 **API** automation remains Phase 3 ([Roadmap.md](Roadmap.md)).

**Operator setup:** [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md) — full Enterprise SSO checklist (Primary Partner Admin, DNS, Finalize).

## User experience

No new dashboard or page. The existing **Pax8 tile** on `/dashboard` works like SuperOps:

1. User clicks **Pax8** on the dashboard.
2. Browser goes to `GET /integrations/pax8/launch` (same tab).
3. Portal redirects to Pax8 with `login_hint={email}`.

| User type | Portal role | Pax8 destination |
|---|---|---|
| On IT technicians | `super_admin`, `account_manager` | Partner login (`https://app.pax8.com/login` + `login_hint`) |
| Customer users | `client_admin`, `client_user` | Company view (`PAX8_COMPANY_URL_TEMPLATE` + `client.pax8_company_id`) when `pax8_sso_enabled` |

If a client user's organisation has no **Pax8 company ID** or **Pax8 access** is disabled, the Pax8 tile is hidden on the dashboard. A direct hit to the launch URL redirects back with an error flash.

**Customer Microsoft SSO:** not offered by Pax8 for company users — see [Pax8CustomerAccess.md](Pax8CustomerAccess.md). For SuperOps + portal login, see [CustomerPortalSso.md](CustomerPortalSso.md).

## Architecture

```
Microsoft Entra → On IT Portal session
                  └── GET /integrations/pax8/launch
                        ├── Team member → app.pax8.com/login + login_hint
                        │                 → Auth0 identifier → Continue
                        │                 → Microsoft (Pax8 Enterprise SSO)
                        │                 → Pax8 partner dashboard
                        └── Client user  → company URL + login_hint
```

Unlike SuperOps, Pax8 does **not** use a separate Entra SAML app in your tenant. Pax8 **Primary Partner Admin** configures Azure AD federation inside Pax8 (**Admin → My Partner Profile → Enterprise SSO**). See [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md).

Mirrors [SuperOpsIntegration.md](SuperOpsIntegration.md) Pillar 2 for launch routing only.

### Code map

| Piece | Path |
|---|---|
| Link type | `PortalLinkType::Pax8Sso` (`pax8_sso`) |
| Service | `app/Services/Pax8/Pax8SsoService.php` |
| Controller | `app/Http/Controllers/Integrations/Pax8LaunchController.php` |
| Route | `routes/web.php` → `integrations.pax8.launch` |
| Client field | `clients.pax8_company_id`, `clients.pax8_sso_enabled` |
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

Technicians always launch to **`https://app.pax8.com`** — required for Enterprise SSO. If `.env` points at `mycommandconsole.com`, the service falls back to `app.pax8.com` (custom URLs do not support federation per Pax8 docs).

After deploy, run `php artisan db:seed --class=PortalLinkSeeder --force` so the dashboard Pax8 link uses `pax8_sso` (not a static external URL).

## Pax8 platform constraints

### `login_hint` is not SSO by itself

The portal passes `login_hint` so Pax8/Auth0 pre-fills the user's email on the identifier screen (`login.pax8.com/u/login/identifier`). **This does not complete authentication or trigger Microsoft SSO by itself.**

Unlike SuperOps requester SAML (portal → Microsoft → SuperOps in one hop), Pax8 uses **Auth0 Universal Login** with **Enterprise SSO (Azure AD)** configured inside Pax8. After federation: identifier → **Continue** → Microsoft → Pax8.

### Partner Enterprise SSO (technicians) — required

Full steps: **[Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md)**

Summary:

| Requirement | Detail |
|---|---|
| Pax8 role | **Primary Partner Admin** to configure (Partner Admin can use SSO after setup) |
| Entra role | **Global Admin** for first consent only |
| Domains | Primary domain + optional aliases in Pax8 → DNS TXT → Verify → **Finalize** |
| Users | Pax8 **app user** per technician; UPN must match Microsoft exactly |
| Launch URL | **`https://app.pax8.com` only** — not `mycommandconsole.com` |
| MFA | Microsoft only after federation (no Pax8 MFA for new federated users) |

Official reference: [Enterprise SSO PDF](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf)

### Customer SSO

Pax8 docs — self-service customer IdP SSO is **not** available yet. Customer launch relies on `login_hint` + per-company URL (`/companies/{companyId}`). Customer must exist as Pax8 user for that company.

### API

OAuth 2.0 ([devx.pax8.com](https://devx.pax8.com/docs/authentication)) — Phase 3 only. The `login.pax8.com/authorize` endpoint is for **third-party OAuth integrations**, not portal SSO launch.

## Operator setup (one-time + per client)

See **[Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md)** for the full checklist. Short version:

### P1. Pax8 Enterprise SSO (technicians)

1. Create **Pax8 app users** with UPN matching Microsoft (before Finalize).
2. **Primary Partner Admin** → **Admin → My Partner Profile → Enterprise SSO → Azure AD**.
3. Email domain `onit.ltd` + aliases → **Create** → DNS TXT → **Verify** → **Finalize**.
4. First login: **Global Admin** accepts Microsoft consent for Pax8.

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
2. Expect `https://app.pax8.com/login?login_hint=…` → Auth0 identifier with email pre-filled.
3. Click **Continue** → Microsoft (after Enterprise SSO Finalize) → Pax8 partner dashboard.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| No Enterprise SSO tab in Pax8 | Not Primary Partner Admin | Use Primary Partner Admin — see [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md) |
| Lands on identifier with email pre-filled, stops there | Expected before Finalize; or must click Continue | Complete Enterprise SSO; click **Continue** |
| No Microsoft redirect after Continue | Domain not verified/finalized, or not Pax8 app user | Re-check domains; create matching app user |
| Login fails after SSO enabled | UPN ≠ Pax8 username | Align Entra UPN, Pax8 user, portal `users.email` |
| SSO on app.pax8.com but not custom URL | Pax8 limitation | Portal always launches `app.pax8.com` |
| Pax8 password/MFA instead of Microsoft | Federation not active | Verify Finalize; check domain list |
| Client user — Pax8 tile missing | No `pax8_company_id` on client | Set company ID in Admin → Clients → Edit |
| Customer expects Microsoft SSO | Not supported by Pax8 yet | Company deep link only |

## Testing checklist

- [ ] Primary Partner Admin completed Enterprise SSO (DNS verified, Finalized)
- [ ] Technician Pax8 app user exists with UPN matching portal email
- [ ] Technician → dashboard → Pax8 → `app.pax8.com/login` → Continue → Microsoft
- [ ] Client approver with `pax8_company_id` → company subscriptions view
- [ ] Client without `pax8_company_id` → Pax8 tile hidden; direct launch shows error
- [ ] `php artisan test --filter=Pax8` passes

## Production deploy

See [Deployment.md — Updating the Application](Deployment.md#updating-the-application) for the full SSH block after Plesk Git pull.

## Related

- [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md) — **step-by-step Enterprise SSO**
- [SuperOpsIntegration.md](SuperOpsIntegration.md) — launch pattern
- [AccessAndSync.md](AccessAndSync.md) — identity model
- [OperatorRunbook.md](OperatorRunbook.md) — day-to-day ops
