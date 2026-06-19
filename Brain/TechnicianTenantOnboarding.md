# New client setup

**Use the in-app wizard:** **Admin → Clients → Edit client** — setup checklist, consent URL, and sync buttons are on that page.

This doc is a backup reference.

| URL | What |
|---|---|
| https://app.onit.ltd | Portal (sign in with Microsoft) |
| https://portal.onit.ltd | SuperOps requester portal |

**Rule:** Every user's **work email** must be the same in M365, SuperOps, and the portal.

**Source of truth:** One M365 security group per customer (`On IT Portal - {Company}`). Add/remove users there — sync handles the rest.

```
M365 group
  → SuperOps SCIM      = requesters stay current
  → Portal group sync  = portal users stay current
```

**You** = technician (portal + SuperOps admin). **Tom** = M365 admin (customer's Entra tenant).

Detail if stuck: [SuperOpsEntraSync.md](SuperOpsEntraSync.md) · [EntraGroupSync.md](EntraGroupSync.md) · [SuperOpsRequesterSsoSetup.md](SuperOpsRequesterSsoSetup.md)

---

## Step 1 — SuperOps client (You)

1. SuperOps MSP console → **Clients** → open customer (create if missing).
2. Copy **Account ID**.
3. Confirm users who need access exist as **requesters** with correct work emails (SCIM will manage these after Step 4).

---

## Step 2 — Portal client (You)

1. https://app.onit.ltd → **Admin → Clients → Add Client**
2. Set:

| Field | Value |
|---|---|
| Name | Match SuperOps client name |
| SuperOps Account ID | From Step 1 |
| SuperOps SSO enabled | ✓ |
| Active | ✓ |

3. Save. Note **client ID** from the edit URL.

**Stop. Get Steps 3–6 from Tom** (tenant ID + group ID).

---

## Step 3 — M365 security group (Tom)

In the **customer's** Entra tenant (not On IT's):

1. **Entra ID → Groups → New group** — name: `On IT Portal - {Company}`
2. Add all users who need portal + SuperOps.
3. Send you: **Tenant ID** + group **Object ID**.

---

## Step 4 — SuperOps SCIM (Tom)

Keeps SuperOps requesters in sync with the group.

1. SuperOps → **Integrations → Microsoft Entra ID → Generate Tokens** → select this client.
2. Customer Entra → new enterprise app → **Provisioning → Automatic**.
3. Paste SuperOps **Tenant URL** + **Auth Token** → Test connection → Save.
4. **Users and groups** → assign `On IT Portal - {Company}`.

---

## Step 5 — Portal Graph consent (Tom)

Open as admin in the **customer** tenant (replace GUIDs):

```
https://login.microsoftonline.com/{customer-tenant-id}/adminconsent?client_id={on-it-portal-client-id}
```

Accept. Allows the portal to read the group for user sync.

---

## Step 6 — SuperOps Client SSO (Tom)

Needed so the SuperOps link on the portal works with `@customerdomain.com` accounts. Separate app from SCIM.

1. SuperOps → **Settings → Requester Login → SSO Protected → Client SSO** → **+ Configuration**.
2. Copy **Entity ID** and **Consumer Service URL**.
3. Customer Entra → new **non-gallery** enterprise app (SAML):
   - Entity ID + Reply URL from SuperOps
   - Claims (lowercase): `email`, `firstname`, `lastname`
   - Assign the same security group
4. Copy SAML cert + **Login URL** back into SuperOps.

---

## Step 7 — Portal sync (You)

1. **Admin → Clients → Edit** customer.
2. Set:

| Field | Value |
|---|---|
| Entra tenant ID | From Tom |
| Entra group ID | From Tom |
| Entra sync enabled | ✓ |

3. Save → **Dry run sync** → **Sync now**.
4. **Admin → Users** — confirm expected users exist.

CLI (SSH): `php artisan portal:sync-entra-users --client={id} --dry-run` then without `--dry-run`.

---

## Step 8 — Test (You)

Incognito window. Use a **customer** email (e.g. `someone@ductec.co.uk`). **Not** `tom.ashby@onit.ltd`.

- [ ] https://app.onit.ltd/login → Sign in with Microsoft → dashboard loads
- [ ] SuperOps card opens **requester** view (not technician / role chooser)
- [ ] https://portal.onit.ltd/#/requester/login → Microsoft → requester dashboard

| Error | Fix |
|---|---|
| Account not set up | Run Step 7 sync |
| SuperOps Error 1027 | Tom: missing `email` SAML claim |
| Role chooser | Wrong account or Client SSO not done |

---

## Step 9 — Hand off

Tell customer: go to **https://app.onit.ltd**, sign in with Microsoft using work email.

---

## After go-live

| Event | Action |
|---|---|
| **New user** | Tom adds to `On IT Portal - {Company}` group. SuperOps + portal update automatically. |
| **User leaves** | Tom removes from group (or disables M365). SuperOps + portal deactivate automatically. Confirm in **Admin → Users**. |

---

## Change log

| Date | Change |
|---|---|
| 2026-06-19 | In-app setup wizard on client edit page |
| 2026-06-19 | Rewritten as blunt step-by-step checklist |
| 2026-06-16 | Initial guide |
