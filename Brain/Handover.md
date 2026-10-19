# On IT Portal - handover

Read this first when starting a new chat. Then read [README.md](README.md). Production branch is **`main`**. Live site: https://app.onit.ltd.

This file is the map of what the app is, what is already live, and what is still waiting on a person.

## What this app is

On IT Portal is the customer and staff website for On IT, an MSP. One Laravel 11 monolith. Customers and staff sign in with Microsoft Entra only. There is no password login.

It shows each customer their own tickets, devices, Microsoft 365 directory, Huntress security, and Dropsuite backups. Staff onboard customers and watch Integration Health. Data is isolated per client.

| Piece | Role |
|---|---|
| SuperOps | Tickets, devices, requester SSO, SCIM |
| Microsoft Entra / Graph | Sign-in, portal user sync, Client SSO apps |
| Huntress | Security cases |
| Dropsuite | Mailbox backups |
| Pax8 | Launch link into the partner portal. A saved company id is the link |
| usecure | Human-risk feed. Designed, not live |

Technicians do every customer-tenant setup themselves with GDAP. Customers are not sent setup links.

## How it runs

- PHP 8.3 on Plesk, Ubuntu host `159.65.83.156`
- Live tree `/var/www/vhosts/onit.ltd/app.onit.ltd` (no `.git`)
- Bare mirror `/var/www/vhosts/onit.ltd/git/laravel_af3fd1`, branch `main`
- Deploy: push `main`, fetch the bare mirror, `git archive main` (not `origin/main`), then migrate, route/config/view clear, `PortalLinkSeeder`, `optimize`. Do not `cache:clear` on a routine deploy
- Queue, cache, and sessions are MySQL. No Redis
- Two queue workers, queues `high` then `default`
- Docs for the exact steps: [Deployment.md](Deployment.md). Diary: [ServerOpsLog.md](ServerOpsLog.md)

**GitHub `main`:** `39fa34a` (mail domains Phase A complete). **Live:** last diary deploy was `9023f1d` (handover guide, 15 Oct 2026) - Phase A is **not** on production yet. Newest row in [ServerOpsLog.md](ServerOpsLog.md) is the live marker.

## What already works

- **Client SSO wire.** The Entra app is set to v2 tokens before the SuperOps Entity ID is saved, so Graph can create the IdP login URL and certificate. Do not add `superops.ai` as a domain on the customer tenant. After Wire, copy the URL and certificate into SuperOps Step 3 and Save. JustLife was wired on 2 October 2026.
- **Pax8.** A saved company id is enough. There is no second "access enabled" tick.
- **First pull.** Saving a client, or finishing Microsoft Accept, queues the first dashboard pull for every sold feed that has no snapshot yet.
- **Auto-refresh.** The checker runs every minute. It queues a feed only once that feed is past its refresh age (about 54 minutes when no customer is online, about 2 minutes when one is). A failed SuperOps pull then waits at least 15 minutes, or the current refresh age if that is longer, before it is queued again.
- **Dropsuite organisation id** is the short number from the API, not the user id shown in Dropsuite. YorPower is `6182`. Finishing Design Services is `11223`. An id like `360787-12` is a user id. SharePoint and sites often 404 on mailbox-only orgs. That is optional and is not a failed backup.
- **SuperOps ticket statuses.** `Waiting on third party` is open, and it is not "waiting on you". `Still Issue`, and any later status whose name contains Still Issue, is open and waiting on the client (emailed, no reply after 24 hours).
- **Entra portal sync** creates portal users for licensed member accounts, and keeps unlicensed shared mailboxes as rows that cannot sign in. A shared mailbox that still has a licence can sign in. The Active badge means the row is active, not that sign-in is allowed.
- **Last month.** On the client home and Reports, This month and Last month are links once a prior month has been stored. Last month shows those stored service figures. The activity list stays recent work. The control stays hidden until that history exists.
- **Sold product coverage.** Staff Admin Dashboard shows the matrix with account managers. Super admins can filter by AM (or no AM). Export CSV downloads the current filter.
- **Mail domains Phase A (code on GitHub `main`, not live until deploy).** Create Client requires name + main domain. Edit has Mail domains panel, checklist step 00, and a red-DNS warning on the setup guide / hand-off. Needs `php artisan migrate` on deploy. Phase B waits until asked. - [MailDomainDns.md](MailDomainDns.md)

## Who is using it

Checked 14 October 2026. The queue was empty and `failed_jobs` was empty. A 3R Systems client admin session was active that morning. JustLife last signed in on 2 October. No other customer had a recent session. Most activity-log rows are the Entra sync job, not people clicking.

## Still waiting on Tom

1. **Deploy mail domains Phase A** when ready. Pull `main`, `git archive`, then `php artisan migrate` (adds `primary_domain` + `client_domains`). Do not start Phase B until you ask.
2. **YorPower, in SuperOps.** Two requesters share one email. Leave it on the correct person and clear it from the other. Until then every Entra sync skips that rename and logs a warning. See [AccessAndSync.md](AccessAndSync.md).
3. **usecure.** Put the partner API key, API URL, and one company id in the team password vault. Do not paste them into chat or git. Then ask for a probe. Steps: [UsecureIntegration.md](UsecureIntegration.md#next-steps). Leave `USECURE_ENABLED=false` until that probe is green.
4. **CoPilot Ready secrets,** if not already done. Rotate both Microsoft app secrets and save them on `https://copilotready.onit.ltd/microsoft`. Noted in [ServerOpsLog.md](ServerOpsLog.md).

## Do not do these

- Do not commit or deploy the local usecure skeleton that may be sitting uncommitted in a working tree. It is not on `main`. Shipping it would add a column and screens before a key exists.
- Do not enable usecure on production.
- Do not `cache:clear` on a routine deploy. It wipes dashboard snapshots.
- Do not verify `superops.ai` on a customer tenant.
- Do not use Unicode dashes. Use ASCII hyphen-minus.
- Do not add Redis, a public API, or a second production branch.
- Temp probes go in `tmp/` only, and are deleted after use. Never leave them under `app/` or `storage/app/`.

## Next product work, when asked

From [Roadmap.md](Roadmap.md) and [NEXT_TASK.md](NEXT_TASK.md), in order:

1. **Mail domains Phase A deploy**, then Phase B only when asked - [MailDomainDns.md](MailDomainDns.md). Pax8 waits.
2. Pax8 billed catalogue, which needs the partner API.
3. Email or Teams when Integration Health stays on a warning.
4. Per-client logo and colour.
5. Average first response, patch posture, and restore retention.

## Start prompt

Paste this into a new chat:

```
Read Brain/Handover.md, then Brain/README.md, before changing anything.
This is On IT Portal, Laravel 11, production branch main, live at https://app.onit.ltd.
Continue from the handover. Do not enable usecure. Do not commit any uncommitted usecure files unless I say the partner key is in the vault and I ask you to probe it.
ASCII hyphens only. Update Brain in the same turn as any code change.
The two open human tasks are the YorPower SuperOps email clash and the usecure partner key.
The next approved task is in Brain/NEXT_TASK.md (mail domains + DNS). Do not start Phase B until Phase A is done and I ask.
```
