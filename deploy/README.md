# VFI Backend — Deploy & Phase 0 status

Monorepo: the **static site** stays at the repo root (unchanged), the **Laravel API** lives in [`backend/`](../backend). One nginx serves both **same-origin** — the invariant every later phase's Sanctum cookie posture depends on.

Tracks [`docs/phases/phase-0-platform-and-delivery-foundation.md`](../docs/phases/phase-0-platform-and-delivery-foundation.md).

## Built locally (works now)

| Piece | Where | State |
|---|---|---|
| Laravel 13 skeleton (PHP 8.4 local / 8.3 target) | `backend/` | ✅ |
| Sanctum + Pennant installed | `backend/composer.json` | ✅ |
| `GET /api/health` (app + DB probe) | `backend/routes/api.php` | ✅ returns 200 |
| Sanctum stateful + empty-string middleware guard | `backend/bootstrap/app.php` | ✅ (P0 step 9) |
| `js/api.js` HTTP seam (cookie mode, CSRF, 401) | `js/api.js` | ✅ (inert until Phase 2) |
| nginx same-origin config | `deploy/nginx/staging.conf` | ✅ authored |
| Docker Compose (nginx/php-fpm/redis/horizon/clamav) | `docker-compose.yml` | ✅ authored |
| PHP-FPM Dockerfile + dedicated uploads pool | `backend/Dockerfile`, `deploy/php/uploads.pool.conf` | ✅ authored |
| CI skeleton (gitleaks, phpstan, pint, tests, audit, cosign) | `.github/workflows/ci.yml` | ✅ authored |

Local dev uses **SQLite** (`backend/database/database.sqlite`); migrations stay portable. Production targets **managed PostgreSQL 16**.

## Needs your cloud accounts (scripted, not yet executed)

These can't run on this machine — they require real provisioning:

- **Managed PostgreSQL 16 + PITR** (app-role without `DROP`). Then set `DB_*` in the injected secrets and swap `DB_CONNECTION=pgsql`.
- **Cloudflare R2** buckets `vfi-media-public` (CDN) + `vfi-docs-private` (no public policy), per-bucket scoped tokens.
- **VPS** (Vultr/DO Singapore or AWS Mumbai — Dhaka latency) + Cloudflare in front + TLS certs into `deploy/nginx/tls/`.
- **GHCR + cosign OIDC trust** so the CI `image` job can push + sign (the sign step is currently a placeholder echo).
- **Secrets manager** + `backend/.env.docker` injected at deploy (never committed).

## Deploy (once the above exist)

```bash
# one-shot migration job, guarded by a Postgres advisory lock (never auto-migrate on boot)
docker compose run --rm php-fpm php artisan migrate --force

# deploy = pull the cosign-verified image and bring the stack up
cosign verify <image> && docker compose pull && docker compose up -d
```

**Rollback:** roll-forward or Postgres PITR only (expand/contract migrations — N safe against N-1 code). No destructive down-migrations in prod.

## Smoke test

```bash
curl -s https://<staging-host>/api/health         # expect 200 {"status":"ok"}
curl -s https://<staging-host>/ | grep '<title>'  # static home served same-origin
```

## Not done yet in Phase 0

- `js/store.js` → HTTP-client shell (must preserve all ~30 exported names and `window.VFI_BOOTSTRAP` behaviour exactly). Deferred to a focused change so the live site never breaks; `js/api.js` is ready for it.
- Wiring `api.js` before `store.js` across the 52 pages (lands with the store.js refactor).
