# Deploy helpers (optional)

This application does **not** ship Docker/Compose. Production today is PHP-FPM behind a reverse proxy (often Plesk).

Files here are **examples with placeholders**. A multi-project platform must substitute its own app root, PHP binary, and user - never hardcode this project's hostname or paths into the platform.

| File | Purpose |
|---|---|
| `systemd/schedule.service.example` | Minute `schedule:run` |
| `systemd/queue.service.example` | Database queue worker |

See repository root [`DEPLOYMENT_HANDOFF.md`](../DEPLOYMENT_HANDOFF.md) for the full contract a deployer must satisfy.
