# 06 — DevSecOps pipeline

What: the CI/CD, container, and supply-chain design for the VFI backend. For engineers and AI agents building/operating the pipeline.

Stack (fixed, per ADR-001): **PHP 8.3 + Laravel 11 + Filament v4 + managed PostgreSQL 16 + Redis + Cloudflare R2**, deployed **same-origin** behind one nginx. Team of 2–4, modest budget, sensitive data (passport scans, bank statements, commission money).

Siblings: [Testing strategy](07-testing-strategy.md) · [Environments & runbooks](08-environments-and-runbooks.md).

> The older NestJS plan in [memory.md](../memory.md) is superseded by ADR-001. This backend is PHP/Laravel.

---

## Assumptions (correct these if wrong)

| # | Assumption |
|---|---|
| A1 | Single prod VPS (4 GB, Singapore/Mumbai) + one **managed** Postgres (PITR) + one managed Redis (or on-box for MVP). API + Filament admin + queue worker run in the *same* Laravel app, split by route prefix (`/api/*`, `/api/admin/*`, `/panel`). |
| A2 | GitHub + GitHub Actions + GHCR. Free tier suffices. |
| A3 | CD orchestrated by **Laravel Forge** on-box, but migrations run as a **one-shot job**, never on boot. Forge runs `docker compose`; CI produces the artifact. |
| A4 | Domains `vfi-edu.com` / `staging.vfi-edu.com` / `dev.vfi-edu.com` — same registrable domain for static site + API (Sanctum cookie requirement). |
| A5 | One person on-call (the lead). "Paging" = push via a free tier (Betterstack/Healthchecks/ntfy). No 24/7 rotation; SLOs reflect that. |

---

## A. Container hardening

Two roles from **one image**: PHP-FPM (API + Filament + worker + scheduler, same image different command) and nginx. Role selection is by command:

| Role | Command |
|---|---|
| API/web | `php-fpm` |
| Worker | `php artisan horizon` |
| Scheduler | `php artisan schedule:work` |

### A.1 `Dockerfile` (multi-stage, non-root, pinned)

```dockerfile
# syntax=docker/dockerfile:1.7
# ---- Stage 1: Composer deps (no dev) ----
FROM composer:2.8@sha256:<pin> AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- Stage 2: Filament/Livewire assets (Vite) ----
FROM node:22-bookworm-slim@sha256:<pin> AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build          # Filament assets only; the 52 public pages are NOT built

# ---- Stage 3: Runtime ----
FROM php:8.3.14-fpm-bookworm@sha256:<pin> AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libwebp-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl gd bcmath opcache \
 && pecl install redis-6.1.0 && docker-php-ext-enable redis \
 && apt-get purge -y --auto-remove && rm -rf /var/lib/apt/lists/*

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/www.conf

RUN groupadd -g 1000 vfi && useradd -u 1000 -g vfi -m -s /usr/sbin/nologin vfi
WORKDIR /var/www/html
COPY --chown=vfi:vfi . .
COPY --from=vendor --chown=vfi:vfi /app/vendor ./vendor
COPY --from=assets --chown=vfi:vfi /app/public/build ./public/build
RUN composer dump-autoload --classmap-authoritative --no-dev \
 && php artisan storage:link \
 && chown -R vfi:vfi storage bootstrap/cache

USER vfi                    # never run as root
EXPOSE 9000
COPY --chown=vfi:vfi docker/entrypoint.sh /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

HEALTHCHECK --interval=15s --timeout=3s --start-period=30s --retries=3 \
  CMD php artisan app:health --probe=liveness || exit 1
```

```bash
# docker/entrypoint.sh
#!/usr/bin/env sh
set -e
if [ -n "$APP_KEY" ]; then          # caches need env present (cloud); skip on first boot
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
fi
# NB: NO `migrate` here. Migrations run as a separate one-shot deploy job (see C.2).
exec "$@"
```

### A.2 Hardening checklist

| Control | How |
|---|---|
| Non-root | `USER vfi` (uid 1000); nginx runs as `nginx` |
| Read-only FS | prod compose `read_only: true` + `tmpfs` for `/tmp`, writable volume only for `storage/` |
| No secrets in image | all via env at runtime; `.dockerignore` excludes `.env`, `.git`, `tests`, `storage/*.key` |
| Pinned bases | digest-pinned (`@sha256:`) in prod build; Renovate bumps them (E.1) |
| Dropped caps | prod: `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]` |
| Minimal surface | `--no-dev` composer, no compilers in runtime stage, apt lists removed |
| Separate FPM pool for uploads | a stalled upload pool must not starve the marketing site (same-origin risk) |

`.dockerignore`:

```
.git
.env*
!.env.example
node_modules
vendor
tests
storage/logs/*
storage/framework/cache/*
*.md
docker-compose*.yml
```

The full local `docker-compose.yml` (Postgres, Redis, MinIO, Mailpit, ClamAV) lives in [Environments & runbooks](08-environments-and-runbooks.md#r1-first-time-local-setup).

---

## B. CI pipeline & security gates

Trigger: every PR to `develop`/`main`, plus a nightly full scan on `main`. Fast gates first (fail-fast), heavy gates in parallel.

### B.1 Gate matrix — BLOCK vs WARN

| # | Gate | Tool | Blocks merge? | Why |
|---|---|---|---|---|
| 1 | Lint / format | Pint + PHPStan (Larastan L6) | **BLOCK** | Cheap, deterministic; L6 catches tenancy/null bugs. |
| 2 | Unit | Pest/PHPUnit | **BLOCK** | Core correctness; includes the **untenanted-query test**. |
| 3 | Integration | Pest + real PG/Redis/MinIO | **BLOCK** | Money paths, migrations, RLS must pass against real PG. |
| 4 | Secret scan | Gitleaks | **BLOCK** | A leaked key is unrecoverable once pushed. Zero tolerance. |
| 5 | SAST | Semgrep (`p/php`, `p/laravel`, `p/owasp-top-ten`) | **BLOCK on High/Critical**, warn medium | Blocks injected `href` schemes, raw SQL, XSS sinks; medium noise shouldn't stall a 3-person team. |
| 6 | SCA / deps | `composer audit` + Trivy fs + `npm audit` | **BLOCK on Critical/High with a fix**, warn otherwise | Actionable blocks; unfixable → warn + tracked issue. |
| 7 | License | `license-checker` allow-list | **BLOCK on denied license** | GPL/AGPL in a dependency = legal exposure. Rare but hard-stop. |
| 8 | Image scan | Trivy image (OS+lib CVE, misconfig) | **BLOCK on Critical**, warn High | Critical base-image CVE shouldn't ship. |
| 9 | IaC scan | Checkov + Trivy config | **BLOCK on High** | Catches container-hardening regressions (root, missing no-new-privileges). |
| 10 | SBOM + provenance | Syft + cosign attest | non-blocking artifact | Produced on every `main` build. |
| 11 | DAST (staging) | OWASP ZAP baseline | **warn** on PR; **BLOCK the promote-to-prod job** on new High | Full active scan is slow/flaky per-PR; it gates the *release*, not the merge. |

Principle: **block the deterministic + unrecoverable + actionable** (secrets, fixable Critical/High CVEs, SAST high, untenanted queries, denied licenses); **warn on noisy/unfixable** (medium SAST, no-fix CVEs) so a small team is never wedged. Every WARN opens a tracked issue.

### B.2 `.github/workflows/ci.yml`

```yaml
name: CI
on:
  pull_request: { branches: [develop, main] }
  push: { branches: [develop, main] }

permissions:
  contents: read
  security-events: write     # upload SARIF to Security tab
  id-token: write            # cosign keyless

concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  static:                    # Gates 1, 4, 5 — fast, fail early
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2, coverage: none }
      - run: composer install --no-interaction --prefer-dist
      - name: Pint (format)
        run: vendor/bin/pint --test
      - name: PHPStan / Larastan L6
        run: vendor/bin/phpstan analyse --no-progress --error-format=github
      - name: Gitleaks (secret scan)          # BLOCK
        uses: gitleaks/gitleaks-action@v2
      - name: Semgrep SAST                      # BLOCK high/critical
        uses: semgrep/semgrep-action@v1
        with:
          config: p/php p/laravel p/owasp-top-ten p/secrets

  test:                      # Gates 2, 3 — real services
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env: { POSTGRES_DB: vfi_test, POSTGRES_USER: vfi, POSTGRES_PASSWORD: secret }
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U vfi" --health-interval 5s
          --health-timeout 3s --health-retries 10
      redis:
        image: redis:7.4
        ports: ["6379:6379"]
      minio:
        image: bitnami/minio:latest
        env: { MINIO_ROOT_USER: minioadmin, MINIO_ROOT_PASSWORD: minioadmin }
        ports: ["9000:9000"]
    env:
      DB_HOST: 127.0.0.1
      REDIS_HOST: 127.0.0.1
      R2_ENDPOINT: http://127.0.0.1:9000
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: pdo_pgsql, redis, gd, bcmath, coverage: pcov }
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan migrate --force               # migrations must apply cleanly
      - name: Pest (unit + integration + tenancy guard) # BLOCK
        run: vendor/bin/pest --coverage --min=70

  compliance:                # Gates 6, 7
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }
      - run: composer install --no-interaction --prefer-dist
      - name: composer audit (SCA)               # BLOCK critical/high w/ fix
        run: composer audit --format=plain
      - name: Trivy filesystem
        uses: aquasecurity/trivy-action@0.28.0
        with:
          scan-type: fs
          scanners: vuln,license
          severity: CRITICAL,HIGH
          exit-code: '1'                          # BLOCK
          ignore-unfixed: true                    # unfixable → warn only
      - name: License allow-list                   # BLOCK denied license
        run: |
          composer require --dev madewithlove/license-checker --no-interaction
          vendor/bin/license-checker check --allow="MIT,BSD-3-Clause,BSD-2-Clause,Apache-2.0,ISC,LGPL-2.1-only"

  image:                     # Gates 8, 9, 10 — build once, scan
    runs-on: ubuntu-latest
    needs: [static, test]
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - name: Build image (load, don't push on PR)
        uses: docker/build-push-action@v6
        with:
          context: .
          target: runtime
          load: true
          tags: ghcr.io/vfi/app:${{ github.sha }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
      - name: Trivy image scan                     # BLOCK critical
        uses: aquasecurity/trivy-action@0.28.0
        with:
          image-ref: ghcr.io/vfi/app:${{ github.sha }}
          severity: CRITICAL
          exit-code: '1'
          ignore-unfixed: true
      - name: Checkov IaC scan                     # BLOCK high
        uses: bridgecrewio/checkov-action@v12
        with:
          directory: .
          framework: dockerfile,github_actions
          soft_fail: false
      - name: Syft SBOM
        uses: anchore/sbom-action@v0
        with:
          image: ghcr.io/vfi/app:${{ github.sha }}
          format: cyclonedx-json
          output-file: sbom.cdx.json
      - uses: actions/upload-artifact@v4
        with: { name: sbom, path: sbom.cdx.json }
```

### B.3 The tenancy-guard test (blocking)

Concretises the ADR's "CI fails on any untenanted partner-table query". Full detail in [Testing strategy](07-testing-strategy.md#5-security-tests).

```php
// tests/Feature/TenancyGuardTest.php
it('scopes every partner-table query by agency_id', function () {
    DB::enableQueryLog();
    actingAsPartner($agencyA);                 // sets session agency + RLS GUC
    Application::query()->get();               // model uses BelongsToAgency global scope
    $sql = collect(DB::getQueryLog())->last()['query'];
    expect($sql)->toContain('"agency_id" =');  // fails if the scope is bypassed
});

it('returns zero rows (RLS) if the scope is stripped', function () {
    seedApplicationsFor($agencyA, 3);
    setPgAgencyGuc($agencyB->id);              // SET LOCAL app.agency_id
    expect(DB::table('applications')->count())->toBe(0);  // RLS second net
});
```

---

## C. CD: branching, migrations, deploy, rollback, flags

### C.1 Branching — trunk-ish GitHub Flow with a staging trunk

Full GitFlow is overkill for 2–4 devs.

```
feature/*  ──PR──▶  develop  ──▶ auto-deploy dev
                       │
                    (merge)
                       ▼
                     main    ──▶ auto-deploy STAGING ──DAST──▶ [manual approve] ──▶ PRODUCTION
                       ▲
                   hotfix/*  (branch from main, PR back to main AND develop)
```

| Branch | Protected | Deploys to | Merge requires |
|---|---|---|---|
| `feature/*` | no | — | — |
| `develop` | yes | dev (auto) | CI green + 1 review |
| `main` | yes | staging (auto), prod (manual gate) | CI green + 1 review + up-to-date + linear history + signed commits |
| `hotfix/*` | no | — | fast-track PR to `main` |

Tags `v1.4.2` cut on prod release; the deployed artifact is the image **digest**, the tag is human-readable. `main` branch protection requires the `static`, `test`, `compliance`, `image` checks and signed commits; no force-push.

### C.2 Migration strategy — expand/contract, never destructive in one release

**Iron rule:** a migration in release *N* must be safe against release *N−1* code still running (zero-downtime overlap) **and** safe to roll code back to *N−1* without a DB restore. Destructive changes split across **≥2 releases**.

Expand → migrate → contract (rename `phone` → `phone_national`):

| Release | Code | Migration | Old+new code both work? |
|---|---|---|---|
| N (expand) | writes both cols | `ADD COLUMN phone_national NULL`, backfill via batch job | yes |
| N+1 (transition) | reads/writes new col only | (none) | yes |
| N+2 (contract) | — | `DROP COLUMN phone` | old code gone — safe |

Execution — one-shot job, never on boot:

```yaml
# .github/workflows/deploy-prod.yml  (the migrate step)
  migrate:
    runs-on: ubuntu-latest
    environment: production          # requires reviewer approval
    needs: [promote-approved]
    steps:
      - name: Run migrations as a one-shot container (NOT in FPM entrypoint)
        run: |
          ssh deploy@$PROD_HOST '
            cd /srv/vfi &&
            docker compose run --rm --no-deps app \
              php artisan migrate --force --isolated
          '
          # --isolated: advisory lock so parallel deploys cannot double-run
```

Authoring rules (enforced in review):

- **Additive only within a release:** `ADD COLUMN ... NULL`, `CREATE INDEX CONCURRENTLY`, new tables. Never `ALTER TYPE` / `DROP` / add `NOT NULL` to a populated column in the same release that ships dependent code.
- **`CREATE INDEX CONCURRENTLY`** on `program_search` (300–600k rows) and hot tables. Laravel: `Schema::withoutTransaction()` — CONCURRENTLY cannot run inside a txn.
- **Backfills are queued batch jobs**, not inline in the migration (a 600k-row `UPDATE` in a migration locks the deploy).
- **Preserve blog ids verbatim** — no id-regeneration migration, ever ([blog-post URLs are the id](../memory.md)).
- Every collection/override list gets `position INTEGER`; new rows default to the **front** (`put()` unshift semantics) to keep the home featured trio.

### C.3 Zero-downtime deploy (rolling replace on one box)

At this budget it's a rolling replace behind nginx, not true blue/green:

```bash
# deploy.sh (run by CD over SSH; Forge equivalent)
set -euo pipefail
cd /srv/vfi
cosign verify \                            # fail-closed: refuse unsigned images (E.3)
  --certificate-identity-regexp "https://github.com/vfi/.*" \
  --certificate-oidc-issuer "https://token.actions.githubusercontent.com" \
  ghcr.io/vfi/app@$DIGEST || { echo "UNSIGNED IMAGE — refusing deploy"; exit 1; }
docker compose pull app worker            # new digest in GHCR; migrations already applied
docker compose up -d --no-deps --scale app=2 --wait app   # start new alongside old, health-gate
docker compose up -d --no-deps --scale app=1 --wait app   # drain old
docker compose up -d --no-deps worker scheduler
docker compose exec -T app php artisan horizon:terminate   # graceful worker cycle
docker compose exec -T app php artisan config:cache route:cache
```

`--wait` + HEALTHCHECK shifts traffic only to a container that passes readiness. `horizon:terminate` lets in-flight jobs (AV scans, emails) finish. Cloudflare fronts the box and absorbs the brief blip.

### C.4 Rollback

Because migrations are **expand-only**, code rollback is always DB-safe:

```bash
# Rollback = redeploy previous signed digest. NO down-migration in prod.
cd /srv/vfi
export PREV=$(cat /srv/vfi/.last_good_digest)
DIGEST=$PREV ./deploy.sh
```

| Scenario | Action | RTO |
|---|---|---|
| Bad release, DB unchanged (normal) | redeploy `PREV` digest | < 5 min |
| Bad release + additive migration | redeploy `PREV` (additive cols ignored by old code) | < 5 min |
| Data corruption from a job | pause queue, redeploy `PREV`, **PITR restore to timestamp** | < 60 min |
| Contract migration shipped too early | the failure mode expand/contract prevents; if it happens → PITR restore | < 60 min |

`.last_good_digest` updates **only after** a post-deploy smoke test passes. Down migrations exist for local/dev but are **not** part of the prod rollback path — prod rolls forward or restores. Restore runbook: [08 §R5](08-environments-and-runbooks.md#r5-restore-from-backup).

### C.5 Feature flags — Laravel Pennant (DB-backed, no third party)

```php
Feature::define('wallet.topup',        fn () => false);   // money OUT — off until PSP live
Feature::define('ai.mock_interview',   fn (User $u) => $u->agency->tier >= Tier::GROWTH);
Feature::define('real_request.student_login', fn () => config('vfi.wiring.student_login'));
```

Uses:

- **Progressive REAL-REQUEST wiring:** each of the 11 `REAL REQUEST` points (see [memory.md](../memory.md)) goes live behind a flag; the per-page demo disclaimer is removed in the *same PR* that flips the flag (project convention).
- **Kill-switches** for expensive/dangerous paths (LLM assistant, mock interview, backup import) — flip off without a deploy.
- **Tenant/tier gating** (interview quota, seat limits).

---

## D. Supply-chain hardening

| Layer | Control | Concrete |
|---|---|---|
| Lockfiles | committed + frozen installs | `composer.lock`, `package-lock.json` committed; CI uses `composer install` (not `update`) and `npm ci`. |
| Pinned bases | digest-pinned in prod | `FROM php:8.3.14-fpm-bookworm@sha256:...`; no `:latest` in `Dockerfile`. Compose infra pinned to minor. |
| Dep updates | automated, reviewed | **Renovate** — grouped PRs, digest pinning, waits for CI. No silent floats. |
| SBOM | every `main` build | Syft → CycloneDX, attached + attested. |
| Provenance / signing | keyless | **cosign** (OIDC) signs the image; SLSA provenance + SBOM attestation. |
| Registry | private, immutable, scanned | GHCR private; immutable tags; Trivy gate on push. |
| Verify at deploy | never run unsigned | `cosign verify` in `deploy.sh` (fail-closed). |
| Commit integrity | signed commits on `main` | branch protection "require signed commits". |
| Action pinning | third-party actions to SHA | `uses: aquasecurity/trivy-action@<sha>` in prod workflows (shown by version above for readability — pin to SHA before shipping). |

### D.1 Renovate (`renovate.json`)

```json
{
  "$schema": "https://docs.renovatebot.com/renovate-schema.json",
  "extends": ["config:recommended", ":pinDigests", ":semanticCommits"],
  "packageRules": [
    { "matchManagers": ["composer"], "groupName": "php deps", "schedule": ["before 6am on monday"] },
    { "matchUpdateTypes": ["patch", "pin", "digest"], "automerge": true, "matchCurrentVersion": "!/^0/" },
    { "matchPackageNames": ["php", "postgres", "redis"], "automerge": false }
  ],
  "vulnerabilityAlerts": { "labels": ["security"], "automerge": false },
  "lockFileMaintenance": { "enabled": true, "schedule": ["before 6am on monday"] }
}
```

Patch/digest bumps auto-merge **after CI passes**; major/runtime bumps and security alerts require human review.

### D.2 Build → sign → attest (`.github/workflows/release.yml`, on `main`)

```yaml
  build-sign:
    runs-on: ubuntu-latest
    permissions: { contents: read, packages: write, id-token: write }
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3
        with: { registry: ghcr.io, username: ${{ github.actor }}, password: ${{ secrets.GITHUB_TOKEN }} }
      - id: build
        uses: docker/build-push-action@v6
        with:
          context: .
          target: runtime
          push: true
          tags: ghcr.io/vfi/app:${{ github.sha }}
          provenance: true          # SLSA provenance attestation
          sbom: true                # inline SBOM attestation
      - uses: sigstore/cosign-installer@v3
      - name: Sign image (keyless, OIDC)
        run: cosign sign --yes ghcr.io/vfi/app@${{ steps.build.outputs.digest }}
      - name: Attest SBOM
        run: |
          syft ghcr.io/vfi/app@${{ steps.build.outputs.digest }} -o cyclonedx-json > sbom.json
          cosign attest --yes --predicate sbom.json --type cyclonedx \
            ghcr.io/vfi/app@${{ steps.build.outputs.digest }}
```

---

## E. Milestone-1 minimum ("safe to deploy", weeks 1–3)

A 5-month backend is the wrong answer to a live emergency (admin.html has no login; the contact form silently drops leads). The DevSecOps subset that must exist before *anything* touches production:

| Must-have | Where |
|---|---|
| Compose stack a dev can `up` in one command | [08 §R1](08-environments-and-runbooks.md#r1-first-time-local-setup) |
| Dockerfile: multi-stage, **non-root**, pinned base | A.1 |
| CI: lint + unit + **secret scan** + **SAST high=block** + **tenancy-guard test** | B.1 gates 1,2,4,5 + B.3 |
| Managed Postgres + **nightly encrypted backup + one restore drill** | [08 §F, §R5](08-environments-and-runbooks.md#f-backup--restore) |
| Migrations as **one-shot job, expand-only**, never on boot | C.2 |
| `/healthz` + `/readyz` + backup dead-man's-switch alert | [08 §H](08-environments-and-runbooks.md#h-healthreadiness-slos-and-alerts) |
| **Admin auth + TOTP live** (the #1 product gap) behind flagged rollout | C.5 |
| Rollback = redeploy previous **signed** digest | C.4, C.3 |

Everything else (DAST-in-CD, Grafana dashboards, Renovate automerge, SLSA provenance) layers on after the site is no longer an unauthenticated public write endpoint.
