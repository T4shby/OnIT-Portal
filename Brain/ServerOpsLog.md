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

### 2026-08-24 - Deploy Beta banner + M365 Excel/CSV export

| | |
|---|---|
| Intent | Ship portal-wide Beta notice and M365 licences/users download for client demo |
| Operator | agent |
| SHA before -> after | `f4ab2dc` -> (pending push tip) |
| Steps | Implement banner + `M365DirectoryExportService`; push `main`; fetch bare mirror; `git archive` to live; artisan migrate/clear/seed/optimize; chown storage; verify routes + sample export on server |
| Result | Pending live verify |
| Rollback / watch | Redeploy prior SHA; hard-refresh client portal; check `/microsoft-365/directory` download buttons |

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
