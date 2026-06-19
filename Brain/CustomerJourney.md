# On IT Portal — Customer Journey

## 1. Invitation

Admin: **Admin → Clients → Add/Edit** (in-app setup wizard) or [ClientOnboarding.md](ClientOnboarding.md). Share portal URL https://app.onit.ltd.

## 2. First Login

1. `/login` → Sign in with Microsoft
2. Email matched to pre-provisioned user
3. SuperOps requester synced by email
4. Dashboard (or `/support` if `SUPEROPS_AUTO_OPEN_AFTER_LOGIN`)

## 3. Daily Use

- **Dashboard** — notices, recommendations, opportunities, service cards
- **Support** (`/support`) — tickets in-portal, no second login
- **SuperOps full portal** — one click → `/#/requester/login` → Microsoft SAML → requester dashboard
- **Pax8 / M365 / etc.** — external tabs

## 4. Returning User

Valid session → dashboard. Expired → Microsoft sign-in again.

## Journey Diagram

```
Invitation → Microsoft login → Dashboard
                ├── Support (embedded)
                ├── SuperOps SSO launch → requester login → Microsoft → SuperOps
                └── External services
```

## Principles

- One Microsoft login for portal + embedded support
- On IT branding throughout embedded areas
- Tenant-scoped content only
