# Phase 0 — Platform & Delivery Foundation

**What this is:** the build sheet for standing up repo, environments, CI/CD, containers and an empty managed database, so a trivial signed and health-checked build deploys to staging through the pipeline with **zero product features**. **Who it's for:** the platform owner and whoever reviews the P0 exit gate.

See [phases/README.md](README.md) for the gate model, [memory.md](../../memory.md) for the frontend it wraps, and [BACKEND_DEVELOPMENT_PLAN.md](../BACKEND_DEVELOPMENT_PLAN.md) for the DevOps/CI-CD section.

| | |
|---|---|
| **Goal** | A signed, health-checked, feature-less build deploys to staging through the pipeline; the unchanged static site is served same-origin with a working `/api/health`. |
| **Duration** | 2–3 weeks |
| **Depends on** | Nothing (this is the root). |
| **Blocks** | Everything. This is a hard-serial predecessor to P1 — no product code before the platform. |

---

## Prerequisites

| Need | Detail |
|---|---|
| Cloud accounts | VPS provider (Vultr/DO Singapore or AWS Mumbai — region matters for Dhaka latency), Cloudflare, managed Postgres provider, Postmark (later), GitHub org |
| Domain | Registrable domain reserved; staging subdomain decided. **Same registrable domain for site and API** — Sanctum cookie posture depends on it. |
| Open question to resolve | Orchestration owner: **Laravel Forge vs raw GitHub Actions**. Write deploy scripts to work either way to avoid rework. |

---

## In scope

1. Repo layout (monorepo, or two repos: Laravel API + the existing static site) with the static frontend imported **unchanged**; branch protection on `main`.
2. **Same-origin topology, proven:** one nginx serving the 52 static files AND proxying `/api/*` to PHP-FPM.
3. Docker Compose stack on a 4 GB VPS: nginx, PHP-FPM (with a **separate FPM pool reserved for uploads**), Redis, Horizon under systemd, ClamAV sidecar.
4. Managed **PostgreSQL 16 with PITR** (NOT on-box); Redis; two Cloudflare R2 buckets (public content-hashed images, private documents) with per-bucket scoped tokens.
5. CI skeleton (GitHub Actions).
6. CD: one-shot migration deploy job with an advisory lock; deploy = `docker compose pull && up -d` behind a cosign-verify gate.
7. Laravel skeleton + the `js/store.js` HTTP-client stub + the `js/api.js` helper; Sanctum + Pennant installed.
8. Secrets manager wired; documented break-glass.
9. Disable `ConvertEmptyStringsToNull` + `TrimStrings` middleware on the (future) content route group.

## Out of scope

- Any product entity or business endpoint (only a health/migrations baseline table exists).
- Any `REAL REQUEST` wiring — the frontend still runs entirely on seeded `VFI_BOOTSTRAP` data.
- Filament install (P1), auth logic (P1), RLS policies (P1/P6), object-storage upload code (P3/P5).

---

## Work breakdown

### 1. Repository & branch protection
1.1 Create the repo(s); import the static site verbatim. No file in `*.html` / `css/` / `js/` is modified except the two seam files in step 7.
1.2 Branch protection on `main`: required CI, required review, linear history, no force-push.
1.3 `CODEOWNERS` for `docs/phases/**` and infra dirs.

### 2. Same-origin nginx topology
2.1 One nginx server block: static root serves the 52 files; `location /api/ { proxy_pass php-fpm; }`.
2.2 TLS + HSTS; Cloudflare in front.
2.3 Prove it: `GET /` returns the static home page and `GET /api/health` returns 200 **from the same origin** (same scheme/host/port). This is the invariant Sanctum cookies will later depend on.

```nginx
# staging server block — the load-bearing invariant is one origin for both
server {
    listen 443 ssl http2;
    server_name staging.vfi-edu.example;
    root /srv/vfi/site;                 # the 52 static files, unchanged
    location /api/ {
        proxy_pass http://php-fpm-upstream;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    location / { try_files $uri $uri/ =404; }
}
```

### 3. Container stack
3.1 Docker Compose: `nginx`, `php-fpm` (default pool + a dedicated `uploads` pool so an upload never starves the marketing site), `redis`, `horizon` (systemd unit), `clamav`.
3.2 Non-root users; digest-pinned base images.
3.3 Validate ClamAV RAM footprint (~1–2 GB) against the 4 GB box under load; record headroom. If it competes with FPM/Redis, plan the R2-scan-hook fallback (documented, not built).

### 4. Managed data services
4.1 Provision managed Postgres 16 with PITR **on**. DB **app-role has no DROP** grant.
4.2 Provision Redis.
4.3 Create two R2 buckets: `vfi-media-public` (content-hashed, CDN-fronted), `vfi-docs-private` (no public policy). Per-bucket scoped tokens.
4.4 Document the server-generated-key convention for private objects **before any upload code exists**.

### 5. CI skeleton (GitHub Actions)
5.1 Stages: install → PHPStan/Larastan (moderate) → Pint → unit test runner → gitleaks → dependency CVE scan → license check → Docker build → **cosign keyless sign** → SBOM/SLSA attestation.
5.2 Mark BLOCK vs WARN per [README gate classes](README.md#ci-gate-classes).
5.3 CI self-test fixtures: a committed fake secret and a known-vuln dependency (see Testing).

### 6. CD & migrations
6.1 One-shot migration job guarded by a Postgres **advisory lock** — never auto-migrate on app boot.
6.2 Deploy = `docker compose pull && up -d`, gated by `cosign verify`. An unsigned/altered image cannot run.
6.3 Expand/contract discipline documented as a standing rule (migration in _N_ safe against _N-1_ code).

### 7. Frontend seam (pure frontend — can start day one)
7.1 Reimplement `js/store.js` as an HTTP-client **shell**: all ~30 exported names present (`SEED data save reset list get put remove settings saveSettings media setMedia country saveCountry region saveRegion servicesPage saveServicesPage partnerPage savePartnerPage partnerPortal savePartnerPortal pageEnabled setPage baseName getImage putImage delImage uploadImage exportAll importAll uid fmtDate fmtDay esc storageOK`), still resolving against `window.VFI_BOOTSTRAP` so behaviour is unchanged. ES5 only.
7.2 Add `js/api.js` (~60 lines ES5): API base URL, `credentials:'include'`, CSRF double-submit header, 401 redirect. Wire it into page load order **before** `store.js`.
7.3 Prove `VFI_BOOTSTRAP` injection: the site renders from an injected `<script>window.VFI_BOOTSTRAP={…}</script>` with no console errors.

### 8. Secrets & config
8.1 Secrets in a manager, never `.env`-in-repo. Documented age-encrypted break-glass env.
8.2 Laravel install + Sanctum + Pennant (no auth logic yet).

### 9. The empty-string landmine
9.1 Disable `ConvertEmptyStringsToNull` and `TrimStrings` on the future content route group **now**, before content code exists. `""`/`[]` means "keep the page's built-in HTML" across 32 pages (see [memory.md store API](../../memory.md)) — Laravel's default middleware would silently null/trim it.

---

## Deliverables

- Running staging URL serving the unchanged static site same-origin with a working `/api/health`.
- Green CI pipeline that blocks on secrets/CVE/SAST/license and produces a signed, attested image.
- Managed Postgres+PITR, Redis, R2 (2 buckets), ClamAV sidecar — all reachable from the app container.
- `js/store.js` HTTP-client seam + `js/api.js` committed; `VFI_BOOTSTRAP` injection working (still local data).
- Runbook: deploy, rollback (roll-forward / PITR only), on-call pager conditions, backup/restore drill procedure.

---

## Security work

| Item | Detail |
|---|---|
| Supply chain | cosign keyless signing + deploy-time verify as a **fail-closed** gate; non-root, digest-pinned containers |
| Secrets | gitleaks in CI (BLOCK); secrets in a manager with documented break-glass; DB app-role without DROP |
| Transport | nginx TLS + HSTS; `Cache-Control: no-store` default posture staged for future authed routes; Cloudflare in front |
| Storage | R2 private bucket has **no public policy**; server-generated-key convention documented before first upload |

---

## Testing

| Test | Passes when |
|---|---|
| CI self-test — secret | A deliberately committed fake secret fails the pipeline |
| CI self-test — CVE | A known-vuln dependency fails the pipeline |
| Deploy smoke | `/api/health` returns 200 through nginx same-origin |
| Signature gate | cosign-verify blocks an unsigned image at deploy |
| Backup-restore drill | Managed-Postgres PITR restore to a scratch instance succeeds and is **timed** (captures RTO baseline) |
| Migration lock | Two concurrent deploys do not double-run migrations |

---

## Exit gate

- [ ] A commit to `main` auto-builds a signed image, blocks on injected secret/CVE, and deploys to staging via the one-shot migration job **with no manual step**.
- [ ] The static site loads same-origin at the staging URL and `/api/health` returns 200 through the `/api` proxy.
- [ ] Postgres is managed with PITR enabled, verified by a **successful point-in-time restore drill**; Redis + both R2 buckets reachable; ClamAV sidecar responds.
- [ ] Running an unsigned/altered image is rejected at deploy; committing a secret fails CI.
- [ ] `js/store.js` exposes all ~30 legacy names and the site renders from `VFI_BOOTSTRAP` with no console errors.

Two-party sign-off per [README gate model](README.md#gate-model).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Same-origin proxy misconfigured now → Sanctum cookie failure later | Prove same-origin in P0, do not assume it. Step 2.3 is an explicit test, not a checkbox. |
| ClamAV RAM (~1–2 GB) competes with FPM/Redis on a 4 GB box | Validate sizing under load in step 3.3; keep the R2-scan-hook fallback documented and ready. |
| Forge vs GitHub-Actions orchestration undecided | Write deploy snippets to work either way; defer the choice, don't let it block. |
| Managed-Postgres region latency to Dhaka | Choose Singapore/Mumbai; measure round-trip in the smoke test. |

---

## Frontend files / REAL REQUEST markers wired

**None.** No `REAL REQUEST` marker is wired this phase.

| File | Change |
|---|---|
| `js/store.js` | Becomes an HTTP-client shell (all ~30 names present), still resolving `window.VFI_BOOTSTRAP` — behaviour unchanged. |
| `js/api.js` | New. ES5 helper: base URL, `credentials:'include'`, CSRF header, 401 redirect. Added to page load order before `store.js`. |

The 11 `REAL REQUEST` markers in `js/auth.js`, `js/partner-auth.js`, `js/portal.js` remain stubbed. No demo disclaimer is removed.

**Next:** [Phase 1 — Identity Spine & Admin Lockdown](phase-1-identity-spine-and-admin-lockdown.md).
