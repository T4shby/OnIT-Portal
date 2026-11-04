# On IT Portal - colleague demo (3 Nov 2026)

Live: https://app.onit.ltd  
Sign-in: Microsoft only (staff = Super Admin / Account Manager; customer = Client Admin).

Use this as a walkthrough. Hard-refresh (Ctrl+F5) if a page looks stale.

---

## 1. Staff Admin - Integration Health

**Where:** Staff Admin → **Integration Health**

**Show:**
- Live pipeline (scheduler, prewarm, queue, stuck / cold / aging)
- Per-client feed columns (SuperOps, M365, Huntress, Dropsuite, SCIM where relevant)
- Notices including mail domain gaps / still-red DNS

**Talking point:** This is the technician source of truth for “is data refreshing?” - not a spreadsheet.

**Optional alerts (ready, off until configured):**
- When severity stays warning/critical for 15+ minutes, portal can post to a **Teams webhook** and/or email
- Not a daily digest - in-app IH stays primary
- Enable later with `INTEGRATION_HEALTH_ALERTS_ENABLED` + webhook/email in production `.env`

---

## 2. Staff - Edit Client mail domains (DNS tester)

**Where:** Staff Admin → **Clients** → pick a client (e.g. 3R) → Edit

**Show:**
1. **Main domain** at the top → **Save client** (creates/updates primary + runs DNS check now) - no need for Add domain
2. **Mail domains** card → **Check DNS** (MXToolbox-style)
3. Traffic lights that matter: **MX / SPF / DKIM / DMARC** (drive red / hand-off)
4. **Advisory** cell collapsed - Autodiscover / MTA-STS / TLS-RPT / BIMI are optional niceties
5. **Advanced** collapsed - Add domain / custom DKIM only for extras or unusual selectors
6. **Provider** from MX, **PENDING** until checked, history + **Export CSV**

**Talking point:** Onboarding step 00 is “do we need to fix DNS?” not “configure DNS in the portal”. Hand-off warns on red core; it does not hard-block.

**Try:** **Check DNS** on the primary, or **Import from Microsoft** if the tenant is connected.

---

## 3. Staff - MFA methods (passkey / SMS readiness)

**Where:** Edit Client → **MFA methods / passkey readiness**  
Or Admin → Clients → `{client}` → MFA methods

**Show:**
- Users / SMS-voice risk / Authenticator / Passkey counts
- Per-user table + **Refresh from Microsoft** + **Export CSV**
- Microsoft dates: passkeys defaulting from Sep 2026; native SMS/voice MFA retires **1 Feb 2027**

**Talking point:** Staff and Client Admins see the same inventory shape; portal does not enrol or change methods.

---

## 4. Client Admin portal (customer view)

Sign in as a **Client Admin** for a tenant with Microsoft connected (or use a colleague’s Client Admin account).

### Dashboard
- Glance cards for sold services
- **This month / Last month** toggle - tickets closed and SLA are London **calendar** month (not rolling 30 days). Early in the month, “this month” can be low while “last month” still shows the full prior month (e.g. 3R: mtd 0 vs prev 14 after `447f96e`).
- Microsoft 365 card → “View Microsoft 365 and MFA”

### Services menu
- **Microsoft 365** - directory + licences
- **MFA methods** - full inventory (Client Admin only; not requesters / billing)
- Tabs: **Directory** | **MFA methods** on those pages

**Show:** Refresh + Export CSV on MFA methods (same data staff use).

---

## 5. Quick demo script (15 minutes)

| Min | Action |
|---|---|
| 0-2 | Staff Admin → Integration Health - green/amber story |
| 2-6 | Edit Client → Mail domains - Check DNS, show core vs extended, history, CSV |
| 6-9 | Edit Client → MFA methods - SMS risk + passkey counts |
| 9-13 | Client Admin → Services → MFA methods + Directory tabs |
| 13-15 | Roadmap ask: Teams alert webhook; usecure when key in vault; MFA Phase 2 campaigns |

---

## 6. What is live today vs waiting

| Live now | Waiting on us / you |
|---|---|
| Mail DNS A+B+C (core + extended + history + CSV) | Fill empty main domains / Import where needed |
| Client Admin + Staff MFA inventory | Teams webhook to turn IH alerts on |
| Staff Admin sold coverage + IH | YorPower SuperOps duplicate emails (human) |
| Entra sync, Huntress, Dropsuite, Pax8 launch | usecure partner key (do not enable yet) |
| | MFA Phase 2 findings/campaigns when asked |
| | Pax8 billed catalogue |

---

## 7. Do not demo

- usecure (not live; skeleton not shipped)
- Changing DNS at the registrar from the portal (read-only checks)
- Asking the customer to complete Entra setup links (technicians do GDAP / delegated setup)

---

## Links

| Page | URL path |
|---|---|
| Integration Health | `/admin/integration-health` |
| Clients | `/admin/clients` |
| Example Edit Client | `/admin/clients/{id}/edit` |
| Staff MFA | `/admin/clients/{id}/mfa-methods` |
| Client Admin MFA | `/microsoft-365/mfa-methods` |
| Client Admin Directory | `/microsoft-365/directory` |

Detail docs: [MailDomainDns.md](MailDomainDns.md), [PasskeyMfaMigration.md](PasskeyMfaMigration.md), [ClientAdminDashboard.md](ClientAdminDashboard.md).
