# Pax8 Customer Access - Setup Guide (On IT)

How **client users** view their **subscriptions and licensing** in Pax8 from the On IT Portal - without partner (wholesale) pricing.

**Not customer Microsoft SSO:** Pax8 [Enterprise SSO](https://www.pax8nebula.com/m/10eadb52f582df44/original/Enterprise-SSO.pdf) is **partner-only**. FAQ: *"Can my self-service customers take advantage of SSO? Not at this time."*

**Technician Pax8 SSO:** [Pax8EnterpriseSsoSetup.md](Pax8EnterpriseSsoSetup.md)

**Full customer journey:** [CustomerPortalSso.md](CustomerPortalSso.md)

---

## What the customer sees

- **Subscriptions** for their organisation at **sell price** (what On IT charges them)
- **Not** partner cost, margins, or other customers' data
- Same Pax8 company record you use for provisioning

---

## Portal side (per client)

### Admin → Clients → Edit

| Field | Action |
|---|---|
| **Pax8 Company ID** | From Pax8 → Companies → customer → UUID in URL |
| **Pax8 access enabled** | ✓ - shows Pax8 tile on dashboard for that client's users |

### Admin → Users

- Create users with **work email** matching Pax8 company user

### Launch URL (automatic)

```
/integrations/pax8/launch
  → https://app.pax8.com/companies/{pax8_company_id}?login_hint={user.email}
```

Tile hidden when Pax8 access disabled or company ID missing.

---

## Pax8 side (per client)

1. **Companies** - customer already exists (you license through Pax8)
2. **Companies → {customer} → Users** - add each approver/viewer
   - Username/email must match portal `users.email`
   - Pax8 sends invite / credentials (no customer Entra federation today)
3. **Pricing** - sell price configured in Pax8 (customer sees buyer price, not your cost)

---

## Customer login flow (today)

```
app.onit.ltd → Sign in with Microsoft (portal OAuth)
  → Dashboard → Pax8
  → app.pax8.com/companies/{id}?login_hint=email
  → Pax8 Auth0 login (may require Pax8 password / invite - not Microsoft SSO)
  → Company subscription view
```

Expect **extra steps** vs SuperOps requester SAML until Pax8 ships customer IdP SSO.

---

## Optional: Pax8 Storefronts (white-label buying)

Separate from the portal tile - branded catalog for **purchasing**:

- Pax8 → **Marketplace → Sell → Storefronts**
- [Storefronts overview](https://www.pax8.com/blog/storefronts-will-change-the-way-you-sell/)

Use for **new sales**; use **company view** (portal tile) for **existing subscription visibility**.

---

## Checklist

- [ ] Pax8 company exists
- [ ] Company users created in Pax8 (matching portal emails)
- [ ] Portal client: `pax8_company_id` + **Pax8 access enabled**
- [ ] Portal users: `client_requester`, `client_billing_admin`, or `client_admin`
- [ ] Test in private window as customer (not technician)

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| No Pax8 tile | Enable Pax8 access + company ID on client |
| Access denied flash | Same - or user not a customer role (`client_requester`, `client_billing_admin`, `client_admin`) |
| Wrong company / access error | Wrong company ID; user not Pax8 company user |
| Password login at Pax8 | Expected - no customer Microsoft SSO yet |
| Sees partner pricing | User has partner role in Pax8 - use company user only |

---

## Change log

| Date | Notes |
|---|---|
| 2026-06-23 | Initial guide; `pax8_sso_enabled` gates dashboard tile |
