# Server ops log (production)

Running diary of **SSH / Plesk / live-site** work on On IT Portal production. Use this when something breaks after a deploy or server change - newest entries first.

**Host:** `root@159.65.83.156` (typical)  
**Live path:** `/var/www/vhosts/onit.ltd/app.onit.ltd`  
**Bare mirror:** `/var/www/vhosts/onit.ltd/git/laravel_af3fd1`  
**Branch:** `main` only  
**Full recipe:** [Deployment.md](Deployment.md)

Agents must append here whenever they work on the server - see `.cursor/rules/server-ops-log.mdc`.

**Never log secrets** (tokens, passwords, full `.env`).

---

## Entry template

```markdown
### YYYY-MM-DD HH:MM UK - short title

| | |
|---|---|
| Intent | ... |
| Operator | agent / human |
| SHA before -> after | `abc` -> `def` (or n/a) |
| Steps | bullet list of what ran |
| Result | success / partial / failed + evidence |
| Rollback / watch | how to undo; what to verify |
```

---

## Log

### 2026-09-07 - Deploy activity log 90-day prune (a468ca9)

| | |
|---|---|
| Intent | Stop unbounded activity_logs growth; keep real client IPs (do not drop 127.0.0.1) |
| Operator | agent |
| SHA before -> after | `66b0942` -> `a468ca9` |
| Steps | `git fetch` on bare mirror; `git archive` into `/var/www/vhosts/onit.ltd/app.onit.ltd`; composer install --no-dev; artisan migrate (none), route/config/view clear, PortalLinkSeeder, optimize; chown storage. Confirmed crontab already runs `schedule:run` every minute. Nightly `model:prune` ActivityLog at 03:20. Removed `/tmp/deploy-prod.sh` after. |
| Result | Success. Live `.deployed-commit` = `a468ca9`. Nothing to migrate. Schedule line present in `routes/console.php`. Existing ~6k rows stay until they age past 90 days (oldest currently 2026-06-16). |
| Rollback / watch | Redeploy `66b0942`. Check Admin Activity Logs still lists recent logins. After 03:20, `storage/logs/scheduler.log` should mention prune when due. Table was ~2.2 MB; watch if it climbs into tens of MB. |

### 2026-09-07 - Check activity_logs size (retention, not skip localhost)

| | |
|---|---|
| Intent | See if Admin Activity Logs is filling disk; whether localhost rows are junk |
| Operator | agent |
| SHA before -> after | n/a (read-only probe) |
| Steps | SSH: one-off PHP under `/tmp` - `SHOW TABLE STATUS` + count localhost IPs; deleted probe after |
| Result | ~6029 rows, **~2.2 MB** data+index. Oldest 2026-06-16. **5767 rows IP 127.0.0.1 / ::1** (real user actions behind nginx, not local junk). Skipping localhost would drop most of the audit trail. |
| Rollback / watch | None |

### 2026-09-04 - One-off 3R SAM discovery export (not a portal feature)

| | |
|---|---|
| Intent | Gather SuperOps per-device software inventory + M365 licences + Huntress agents for 3R Systems SAM research; deliver under local `3R task/` folder |
| Operator | agent |
| SHA before -> after | n/a (no app deploy) |
| Steps | SSH: run throwaway PHP under `/tmp` against live Laravel (list assets, `getAssetSoftwareList` per asset, M365 directory/insights, Huntress agents); tar to `/tmp/3r-sam-export.tgz`; scp to laptop; delete `/tmp` probes and server export; build markdown locally |
| Result | Success. 23 devices, ~6949 software rows, 57 M365 people, 22 Huntress agents. Output only on laptop in `3R task/` (gitignored). |
| Rollback / watch | None. Do not leave probes on production. Client data must not be committed. |

### 2026-08-24 - SuperOps ticket body HTML formatting

| | |
|---|---|
| Intent | MSP-wide: SuperOps collapses plain newlines in ticket HTML; new starter looked like one blob. Send HTML lists / br; never Name <email>. |
| Operator | agent |
| SHA before -> after | `2f8cc03` -> `9586fe0` |
| Steps | NewStarterTicketService HTML body; createTicket nl2br for plain tickets; portal show sanitizes HTML; tests; Brain; `git fetch` + `git archive main`; artisan migrate/route/config/view clear, PortalLinkSeeder, optimize. No live ticket probes. |
| Result | Success. Live `.deployed-commit` = `9586fe0`. Existing SuperOps conversations unchanged. |
| Rollback / watch | Redeploy `2f8cc03`; submit a new starter to see the list layout |

### 2026-08-24 - Fix getTicket show (Ticket has no description)

| | |
|---|---|
| Intent | MSP-wide: create succeeds then `/support/{id}` flashes Ticket not found because getTicket selected a non-existent `description` field |
| Operator | agent |
| SHA before -> after | `12826b0` -> `cc77355` |
| Steps | Stop selecting `description` on getTicket; load opening text from getTicketConversationList; log show failures; Http::fake tests; Brain; `git fetch` + `git archive main`; artisan migrate/route/config/view clear, PortalLinkSeeder, optimize. No live named-client probes. |
| Result | Success. Live `.deployed-commit` = `cc77355`. |
| Rollback / watch | Redeploy `12826b0`; open any ticket from Support list or after New starter |

### 2026-08-24 - Document MSP-wide createTicket + GraphQL clientError handling

| | |
|---|---|
| Intent | Treat portal ticket create as one MSP contract for every client (not a named-customer fix); document the SuperOps validation failures; surface `extensions.clientError` in the GraphQL client |
| Operator | agent |
| SHA before -> after | `e03e4c4` -> `cda3ec1` |
| Steps | Http::fake tests only (no live ticket create); SuperOpsApiClient treats `extensions.clientError` as failure; createTicket throws if no ticketId; Brain createTicket contract; `git fetch` + `git archive main` into live path; artisan migrate/route/config/view clear, PortalLinkSeeder, optimize |
| Result | Success. Live `.deployed-commit` = `cda3ec1`. Verified live files contain `requestType` + `collectClientErrors`. |
| Rollback / watch | Redeploy `e03e4c4`; any client Log a ticket / New starter; Laravel log should include SuperOps `clientError` if SuperOps rejects a field |

### 2026-08-24 - Fix SuperOps createTicket requestType (new starter)

| | |
|---|---|
| Intent | Unblock portal createTicket for **all** SuperOps-linked clients (`requestType` mandatory on this MSP) |
| Operator | agent |
| SHA before -> after | `9ce9a4c` -> `45bcfe7` (docs SHA `e03e4c4`) |
| Steps | Diagnostic GraphQL probe (one linked account, then deleted from `/tmp`); add `requestType` to shared `createTicket`; `SUPEROPS_DEFAULT_REQUEST_TYPE`; Brain; push + archive deploy. Do **not** keep customer-specific payload. |
| Result | Success. Live `.deployed-commit` reached `e03e4c4`. Probe tickets 13757/13758 were API checks only - close in SuperOps if unwanted. |
| Rollback / watch | Any client New starter / Log a ticket; see createTicket contract in SuperOpsIntegration.md |

### 2026-08-24 - Fix SuperOps ticket create source for new starter

| | |
|---|---|
| Intent | Unblock New starter / Log a ticket - SuperOps rejected source PORTAL |
| Operator | agent |
| SHA before -> after | `82c926a` -> `9ce9a4c` |
| Steps | Change createTicket source to INTEGRATION + subSource On IT Portal; log failures; push + deploy; live probe |
| Result | Partial: PORTAL fixed, but create still failed - MSP requires requestType (see next entry) |
| Rollback / watch | See requestType fix entry |

### 2026-08-24 - Widen Contact Support on desktop

| | |
|---|---|
| Intent | Use the extra browser width so Contact Support is not a narrow 64rem column |
| Operator | agent |
| SHA before -> after | `4f78627` -> `0cb5641` |
| Steps | Hub/new-starter/ticket pages + header/footer to `max-w-[96rem]`; push + archive deploy |
| Result | Success. Live `.deployed-commit` = `0cb5641`. Contact Support uses same wide width as M365. |
| Rollback / watch | Redeploy `4f78627`; hard-refresh `/contact-support` |

### 2026-08-24 - Beta banner above nav (centered)

| | |
|---|---|
| Intent | Move Beta notice above the header and center the copy |
| Operator | agent |
| SHA before -> after | `d2b3106` -> `7b19095` |
| Steps | Move banner out of main into strip above header; center text; push + archive deploy |
| Result | Success. Live `.deployed-commit` = `7b19095`. Banner markup sits above header. |
| Rollback / watch | Redeploy `d2b3106`; hard-refresh |

### 2026-08-24 - Deploy Contact Support hub + new starter form

| | |
|---|---|
| Intent | Client Contact Support nav/hub (ticket, phone/hours, new starter → SuperOps); exclude technicians |
| Operator | agent |
| SHA before -> after | `0635f34` -> `6c87fad` |
| Steps | Push `main`; bare fetch + archive; post-deploy artisan; route:list contact-support; config phone/email check; curl `/contact-support` → 302 (auth) |
| Result | Success. Live `.deployed-commit` = `6c87fad`. Routes registered; nav markup present; config defaults live. |
| Rollback / watch | Redeploy `0635f34`; hard-refresh client portal; Contact Support next to Services |

### 2026-08-24 - Deploy Beta banner + M365 Excel/CSV export

| | |
|---|---|
| Intent | Ship portal-wide Beta notice and M365 licences/users download for client demo |
| Operator | agent |
| SHA before -> after | `f4ab2dc` -> `fa9fd28` |
| Steps | Push `main`; bare fetch + `git archive` to live; composer + migrate + route/config/view clear + PortalLinkSeeder + optimize; chown storage; live probe of `M365DirectoryExportService` for client 5 (Ductec); confirmed export route 302 when unauthenticated |
| Result | Success. Live `.deployed-commit` = `fa9fd28`. Probe: 4 licences, 22 users, CSV sections ok, XLSX starts with `PK`. PHP zip extension present. |
| Rollback / watch | Redeploy `f4ab2dc`; hard-refresh Dashboard (Beta banner) and Services → Microsoft 365 (Download Excel/CSV) |

### 2026-08-24 ~11:12 UK - Sync check before feature work

| | |
|---|---|
| Intent | Confirm laptop / GitHub / live match before implementing features |
| Operator | agent |
| SHA before -> after | n/a (read-only check; tip remains `b40dcef`) |
| Steps | `git fetch origin`; compare local `main`, `origin/main`, live `.deployed-commit`, bare `main` |
| Result | Check: all three at `b40dcef`. Then shipped this diary row: live `.deployed-commit` = `0d3a017` |
| Rollback / watch | Redeploy `b40dcef` if needed; this change is docs only |

### 2026-08-21 - Deploy ServerOpsLog + Cursor rule to GitHub and live

| | |
|---|---|
| Intent | Put the ops diary and always-on Cursor rule on `main` and production |
| Operator | agent |
| SHA before -> after | `987a8c7` -> `376ff6b` |
| Steps | Commits `f69dd6f` (log+rule) + `376ff6b` (SHA note); `git push origin main`; fetch bare mirror; `git archive main \| tar -x` into live; post-deploy artisan block; chown storage |
| Result | Success. Live `.deployed-commit` = `376ff6b`; `Brain/ServerOpsLog.md` and `.cursor/rules/server-ops-log.mdc` present on server |
| Rollback / watch | Redeploy `987a8c7` from bare mirror if this release misbehaves; confirm `Brain/ServerOpsLog.md` exists on live |

### 2026-08-21 - Server ops log + Cursor rule introduced (local)

| | |
|---|---|
| Intent | Ensure every future production SSH/deploy session leaves a recovery trail |
| Operator | agent |
| SHA before -> after | n/a (docs + rule only until shipped above) |
| Steps | Added `.cursor/rules/server-ops-log.mdc` (alwaysApply); created this file; indexed in README + Deployment |
| Result | Rule active locally; not on live until deploy entry above |
| Rollback / watch | Remove rule/file only if deliberately retiring the practice |

### 2026-08-19 - Deploy equal-height Dashboard cards `987a8c7`

| | |
|---|---|
| Intent | Ship Dashboard service card equal-height layout for demo |
| Operator | agent (prior session) |
| SHA before -> after | `9458ac3` -> `987a8c7` |
| Steps | `git push origin main`; fetch bare mirror; `git archive main \| tar -x` into live path; composer + migrate + route/config/view clear + PortalLinkSeeder + optimize; chown storage |
| Result | Live `.deployed-commit` = `987a8c7`; glance partial contains `glance-card-service` |
| Rollback / watch | Redeploy prior SHA from bare mirror if cards regress; hard-refresh `/dashboard` |

### 2026-08-19 - Deploy Support and Devices restart pager fix `9458ac3` (backfill)

| | |
|---|---|
| Intent | Keep restart-needed device name pager inside the same card chrome |
| Operator | agent (prior session) |
| SHA before -> after | `c727d08` -> `9458ac3` |
| Steps | Push `main`; Plesk bare fetch + archive deploy; post-deploy artisan (no `cache:clear`) |
| Result | Shipped on production in same demo day (confirmed via later live SHA progression) |
| Rollback / watch | Redeploy `c727d08` if pager layout regresses |

### 2026-08-19 - Deploy restart threshold + name pagination `c727d08` (backfill)

| | |
|---|---|
| Intent | Restart-needed from 2+ days uptime; paginate device names; remove RAM/disk placeholder card |
| Operator | agent (prior session) |
| SHA before -> after | `62dfbcc` -> `c727d08` |
| Steps | Push `main`; bare fetch + archive; post-deploy artisan |
| Result | Shipped; SuperOps dashboard cache key `superops-dashboard:v5` (clients may need Refresh/prewarm) |
| Rollback / watch | Redeploy `62dfbcc`; clear SuperOps dashboard cache if metrics look stale |

### 2026-08-19 - Deploy Services nav + Support and Devices `62dfbcc` (backfill)

| | |
|---|---|
| Intent | Replace Organisation with Services dropdown; SuperOps on Support and Devices; M365 licences on Microsoft 365; Huntress titled Security |
| Operator | agent (prior session) |
| SHA before -> after | prior `main` -> `62dfbcc` |
| Steps | Push `main`; bare fetch + archive; post-deploy artisan; may need `rm` of leftover deleted blades if archive left orphans |
| Result | Shipped; Support and Devices at `/services/support-devices` |
| Rollback / watch | Redeploy prior SHA; check nav + routes after rollback |

---

## Change log (this file)

| Date | Note |
|------|------|
| 2026-08-24 | Sync check: local = GitHub = live at `b40dcef` |
| 2026-08-21 | File created; Cursor rule `server-ops-log.mdc`; backfilled 19 Aug deploys; shipped to GitHub + live |
| 2026-08-19 | Deploy of `987a8c7` (logged retrospectively) |
