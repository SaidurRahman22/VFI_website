# 08 — Environments & runbooks

What: the four environments, config/secrets, health/SLOs, backups, and step-by-step operational runbooks for the VFI backend. For engineers and AI agents operating the system (including at 2am).

Stack: **PHP 8.3 + Laravel 11 + Filament v4 + managed PostgreSQL 16 + Redis + Cloudflare R2**, same-origin behind one nginx.

Siblings: [DevSecOps pipeline](06-devsecops-pipeline.md) · [Testing strategy](07-testing-strategy.md).

---

## A. Environments

| | local | dev (integration) | staging (pre-prod) | production |
|---|---|---|---|---|
| Purpose | dev laptop, offline-capable | shared CI target; DAST runs here | prod mirror; release rehearsal, restore drills | live |
| Runs on | Docker Compose | small VPS (2 GB) | VPS sized like prod (4 GB) | 4 GB VPS + managed PG |
| Postgres | container `postgres:16` | container | **managed PG** (small tier) | **managed PG + PITR** |
| Redis | container | container | managed/on-box | managed/on-box |
| Object storage | **MinIO** container | MinIO / R2 dev bucket | R2 staging buckets | R2 prod buckets |
| Mail | **Mailpit** | Mailpit | Postmark **sandbox** | Postmark live |
| ClamAV | container (optional) | container | container | container |
| Data | seeded demo (`VFI.SEED`) | seeded + synthetic | **prod-shaped, PII-scrubbed clone** | real |
| `APP_DEBUG` | `true` | `true` | `false` | `false` |
| `APP_ENV` | `local` | `dev` | `staging` | `production` |
| Turnstile | test keys (always-pass) | test keys | live keys | live keys |
| TLS | off (`http://localhost`) | Let's Encrypt | Let's Encrypt | Let's Encrypt + HSTS preload |
| Auto-deploy | n/a | on merge to `develop` | on merge to `main` | **manual approval** after staging green |
| Who can reach | dev | team | team + client UAT | public |

**Key rule:** staging is the *only* place DAST and restore drills run, and it must be **prod-identical in topology** (managed PG, R2, real ClamAV scan-gate) or the rehearsal is worthless. Its data is a scrubbed clone of prod ([07 §6](07-testing-strategy.md#6-test-data-management)).

Same-origin (static site + API behind one nginx) is a **hard invariant** in every environment — Sanctum HttpOnly cookie sessions depend on it. Moving the static site to a different registrable domain is a documented review trigger, not a config tweak.

---

## B. Config strategy

Laravel 12-factor `.env`. Rules:

1. **No secrets in the repo, ever.** `.env` is git-ignored; commit `.env.example` (keys only, dummy values).
2. **Config read only through `config/*.php`** (`config('services.r2.key')`), never `env()` outside config files — so `php artisan config:cache` works in every non-local env.
3. **Disable the empty-string landmines on content routes** — the load-bearing "empty means keep the page's built-in HTML" contract across 32 pages:

```php
// bootstrap/app.php — Laravel 11 middleware config
->withMiddleware(function (Middleware $middleware) {
    $middleware->trimStrings(except: ['/api/admin/content/*']);
    $middleware->convertEmptyStringsToNull(except: ['/api/admin/content/*']);
})
```

### B.1 `.env.example` (committed)

```dotenv
APP_NAME="VFI Overseas Education"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
APP_TIMEZONE=Asia/Dhaka           # events/blogs stored as DATE; rendered en-US client-side

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=vfi
DB_USERNAME=vfi
DB_PASSWORD=secret                 # local only

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=false        # true in staging/prod
SANCTUM_STATEFUL_DOMAINS=localhost
SESSION_DOMAIN=localhost

QUEUE_CONNECTION=redis
CACHE_STORE=redis

FILESYSTEM_PUBLIC_DISK=r2_public
FILESYSTEM_PRIVATE_DISK=r2_private
R2_ENDPOINT=http://minio:9000
R2_PUBLIC_BUCKET=vfi-media
R2_PRIVATE_BUCKET=vfi-docs
R2_ACCESS_KEY_ID=minioadmin
R2_SECRET_ACCESS_KEY=minioadmin
R2_REGION=auto

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@vfi-edu.com

CLAMAV_HOST=clamav
CLAMAV_PORT=3310

TURNSTILE_SITEKEY=1x00000000000000000000AA   # Cloudflare test key (always passes)
TURNSTILE_SECRET=1x0000000000000000000000000000000AA

SENTRY_LARAVEL_DSN=
LOG_CHANNEL=stderr                 # single-line JSON to stdout
```

---

## C. Secrets

| Env | Store | Rotation |
|---|---|---|
| local | plaintext `.env` from `.env.example`; MinIO/Mailpit creds are non-secret dummies; `APP_KEY` per-dev | never |
| dev | **GitHub Actions Environment secrets (`dev`)** injected at deploy; on-box `.env` `chmod 600` | quarterly |
| staging | GitHub Environment secrets (`staging`); Postmark **sandbox** token; R2 keys scoped to staging buckets only | quarterly |
| production | **GitHub Environment secrets (`production`, protected + required reviewer)**; on-box `.env` `chmod 600 deploy:deploy` | **90 days**, or immediately on suspected exposure |

- **Unique per env, must exist:** `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, R2 `ACCESS_KEY_ID`/`SECRET_ACCESS_KEY` (two keypairs — public bucket RW, private bucket RW), `POSTMARK_TOKEN`, `TURNSTILE_SECRET`, `SENTRY_LARAVEL_DSN`.
- **Break-glass:** production `.env` also backed up, `age`/`sops`-encrypted, in a separate private repo/bucket so a box loss doesn't lose the ability to redeploy. That encrypted file is the *only* place prod secrets live outside GitHub. Rotation runbook: [§R6](#r6-rotate-a-leaked-secret).
- **Upgrade path (not MVP):** move to Doppler / Infisical if the team grows past ~4 or multiple services need shared secrets. GitHub Environments suffice now.

---

## H. Health, readiness, SLOs and alerts

### H.1 Endpoints

```php
// routes/api.php
Route::get('/healthz', HealthController::class.'@liveness');   // process alive?
Route::get('/readyz',  HealthController::class.'@readiness');  // can it serve traffic?
```

```php
public function readiness() {
    $checks = [
        'db'     => rescue(fn () => DB::connection()->getPdo() && true, false),
        'redis'  => rescue(fn () => Redis::ping() === 'PONG', false),
        'r2'     => rescue(fn () => Storage::disk('r2_private')->exists('.probe'), false),
        'clamav' => rescue(fn () => app(ClamAv::class)->ping(), false),
    ];
    $ok = !in_array(false, $checks, true);
    return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
                            $ok ? 200 : 503);
}
```

- **`/healthz`** — liveness: process up, no dependency checks (Docker HEALTHCHECK + deploy `--wait`). Never fails on a slow DB (avoids restart storms).
- **`/readyz`** — readiness: dependency checks; a 503 pulls the container from the nginx upstream but does **not** kill it.
- **ClamAV degraded** ⇒ readiness stays ok for reads but the **upload route** returns 503 (fail-closed: never accept a document you can't scan).

### H.2 SLOs and paging

| SLO | Target | Measured by | Alert threshold | Pages a human? |
|---|---|---|---|---|
| Public marketing availability | 99.9% / 30d | Cloudflare + `/healthz` synthetic | down > 3 min | **YES** |
| API availability (authed) | 99.5% / 30d | `/readyz` synthetic | 503 > 5 min | **YES** |
| API latency (read) | p95 < 400 ms | `http_request_duration_seconds` | p95 > 800 ms for 10 min | warn; page if 30 min |
| Program search latency | p95 < 400 ms | `program_search_duration_seconds` | p95 > 400 ms sustained | warn (ADR review trigger) |
| OTP/email delivery | p95 queue wait < 30 s | `queue_wait_seconds{queue=emails}` | > 120 s for 10 min | **YES** (auth front door) |
| Wallet txn error rate | < 0.1% | `wallet_transaction_total{status=failed}` | > 1% / 5 min | **YES** (money) |
| AV infected upload | event | `document_scan_total{result=infected}` | any occurrence | warn + auto-quarantine |
| Failed-login burst | — | `login_attempts_total{result=fail}` | > 100 / min single IP | warn (Turnstile + rate-limit already active) |
| Backup success | 100% nightly | backup job exit + dead-man's-switch | missed nightly ping | **YES** |
| Cert expiry | > 14 days | blackbox exporter | < 14 days | warn |
| Disk / DB storage | < 80% | node/pg exporter | > 85% | warn; page at 95% |

**The 5 that wake the lead:** total outage, API outage, auth-email pipeline stalled, wallet error spike, missed backup. Everything else is a next-business-day Slack `#alerts` warning — appropriate for a single on-call with no rotation.

**Stack:** structured JSON logs to stdout (Monolog, `request_id` on every line, PII/secret denylist redactor — never log passwords, OTP plaintext, reset tokens, document bytes, session values). Prometheus + Grafana (small containers or Grafana Cloud free tier); Horizon exposes queue throughput. Sentry for errors + tracing (`traces_sample_rate` 0.2 prod / 1.0 staging; money + document paths traced at 100%). Alertmanager → Slack (`warn`) / push+call via Betterstack or ntfy (`page`). Dead-man's-switch on backups so *silence itself* alerts. Append-only domain audit (`auth_events`, `document_access_log`, content audit) are **DB tables**, not app logs — queryable, retained ≥12 months, app role has no UPDATE/DELETE grant.

---

## F. Backup & restore

| Target | RPO (max loss) | RTO (max downtime) | Mechanism |
|---|---|---|---|
| PostgreSQL (managed) | **≤ 5 min** | **≤ 60 min** | managed PITR/WAL + nightly logical `pg_dump` to R2 |
| R2 private docs | **0** (versioned) | minutes | bucket versioning + lifecycle; cross-region replication (v2) |
| R2 public media | ≤ 24 h | minutes | reproducible from admin re-upload; versioning on |
| Redis | **tolerated total loss** | n/a | sessions/rate-limits/queue only — rebuildable; not a source of truth |
| Secrets | 0 | minutes | `age`-encrypted `.env` in separate private repo (§C) |

Money ledger + passport metadata live in Postgres → tightest RPO goes there (managed PITR is *why* the ADR overrode self-hosted Postgres). Documents are in versioned object storage (delete = new version) → RPO 0.

**Jobs** (scheduler):

```php
$schedule->command('backup:pg-dump')->dailyAt('02:30')
         ->onSuccess(fn () => Http::get(config('vfi.healthcheck.backup_ping')));  // dead-man's-switch
$schedule->command('backup:prune')->weekly();                     // GFS: 7 daily · 4 weekly · 12 monthly
$schedule->command('media:orphan-sweep')->dailyAt('03:30');
$schedule->command('documents:retention-purge')->dailyAt('04:00'); // GDPR retention clock
```

```bash
# artisan backup:pg-dump wraps:
pg_dump --format=custom --no-owner "$DATABASE_URL" \
 | age -r "$BACKUP_AGE_PUBKEY" \                          # encrypt at rest
 | aws s3 cp - "s3://vfi-backups/pg/$(date +%F_%H%M).dump.age" --endpoint-url "$R2_ENDPOINT"
```

Backups are **encrypted** (`age`) and in a **separate R2 account/bucket** from app data (blast-radius isolation).

**Drill cadence** — a backup never restored is not a backup:

| Drill | Frequency | Success criterion |
|---|---|---|
| Automated restore-to-staging + smoke | **monthly** | RTO < 60 min, RPO < 5 min, smoke green |
| Full DR tabletop (box loss) | **quarterly** | rebuild from image + PITR + `.env` in < 4 h |
| Backup integrity check (restore last night's dump to throwaway PG) | **nightly** (automated) | `pg_restore --list` + row-count sanity |
| Secret break-glass | **quarterly** | decrypt `.env`, redeploy to a scratch box |

---

## Runbooks

Each runbook is numbered steps. Copy-paste ready; substitute `$PROD_HOST` etc.

### R1. First-time local setup

```bash
git clone git@github.com:vfi/backend.git && cd backend
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# App:    http://localhost:8080     Mailpit: http://localhost:8025
# MinIO:  http://localhost:9001     Admin:   http://localhost:8080/panel
```

The compose file brings up: `web` (nginx), `app` (PHP-FPM), `worker` (Horizon), `scheduler`, `postgres`, `redis`, `minio` + a one-shot `createbuckets` (makes `vfi-media` public-read + `vfi-docs` private), `mailpit`, `clamav`. ClamAV needs ~120 s on first boot to download signatures (`start_period` in its healthcheck). If uploads 503 on a fresh stack, wait for ClamAV `PONG`.

### R2. Deploy to production

Prereq: change is green on `main` and auto-deployed to staging; staging smoke + ZAP baseline green.

```
1. Open the "Promote to production" GitHub Actions run (environment: production).
2. A required reviewer approves the `production` environment gate.
3. Job order (automatic):
   a. migrate  — one-shot container, `php artisan migrate --force --isolated` (advisory lock)
                 → migrations are expand-only, safe against the still-running N-1 code.
   b. deploy   — deploy.sh over SSH:
                 - cosign verify (fail-closed; unsigned image refuses to run)
                 - docker compose pull app worker
                 - scale app=2 --wait (health-gate new container) → scale app=1 (drain old)
                 - recreate worker/scheduler; horizon:terminate (graceful)
   c. smoke    — hit /readyz + journeys 1,2,5 (07 §4.2) against prod.
4. On smoke green: write the new digest to /srv/vfi/.last_good_digest.
5. Announce in #deploys. Watch Grafana + Sentry for 15 min.
```

Never run `migrate` from the FPM entrypoint or on app boot (multi-replica race). See [06 §C.2–C.3](06-devsecops-pipeline.md#c2-migration-strategy--expandcontract-never-destructive-in-one-release).

### R3. Roll back a bad deploy

Because migrations are **expand-only**, code rollback is DB-safe (no down-migration in prod).

```bash
# 1. Confirm the DB was not corrupted (if it was, go to R5 instead).
# 2. Redeploy the previous signed digest:
cd /srv/vfi
export DIGEST=$(cat /srv/vfi/.last_good_digest)
./deploy.sh                       # cosign-verifies + rolling-replaces to $DIGEST
# 3. Verify: curl -fsS https://vfi-edu.com/readyz  → {"status":"ok"}
# 4. If a job corrupted data: pause the queue first, then R5 (PITR to a timestamp before the bad run).
```

| Scenario | Action | RTO |
|---|---|---|
| Bad release, DB unchanged | redeploy `PREV` digest | < 5 min |
| Bad release + additive migration | redeploy `PREV` (additive cols ignored by old code) | < 5 min |
| Data corruption from a job | pause queue, redeploy `PREV`, then R5 | < 60 min |

### R4. Run a migration safely

```
1. Author additive-only within a release: ADD COLUMN ... NULL, CREATE INDEX CONCURRENTLY, new tables.
   NEVER ALTER TYPE / DROP / add NOT NULL to a populated column in the same release that ships
   dependent code. Destructive change = split across >=2 releases (expand → transition → contract).
2. Big-table index? Use CREATE INDEX CONCURRENTLY inside Schema::withoutTransaction()
   (CONCURRENTLY cannot run in a txn). Targets: program_search (300-600k rows), hot tables.
3. Backfill? Queue a batch job — never a 600k-row UPDATE inside the migration (locks the deploy).
4. Preserve blog ids verbatim — no id-regeneration migration, ever.
5. New collection/override rows default to position at the FRONT (put() unshift) — keeps the
   home featured trio deterministic.
6. Test locally: php artisan migrate then migrate:rollback on a seeded DB — must be clean.
7. Ships via the R2 one-shot migrate job with --isolated (advisory lock). Never on boot.
```

### R5. Restore from backup

Tested RPO ≤ 5 min, RTO ≤ 60 min. Run the drill on **staging** monthly; the same steps recover prod.

```bash
# 1. Provision a fresh managed PG instance (or use the console's restore-to-new-instance).
# 2a. PITR (preferred, tightest RPO): managed console → "restore to <UTC timestamp>".
# 2b. OR logical restore from the nightly encrypted dump:
aws s3 cp s3://vfi-backups/pg/2026-08-09_0230.dump.age - --endpoint-url $R2_ENDPOINT \
  | age -d -i backup.key \
  | pg_restore --no-owner --clean --if-exists -d "$TARGET_DATABASE_URL"
# 3. Point the app at the restored DB; run readiness:
curl -fsS https://<env>/readyz
# 4. Smoke: login, load a partner dashboard, read a document via signed URL, verify a wallet balance.
# 5. Record wall-clock restore time (RTO evidence) and last-txn timestamp (RPO evidence).
# 6. NON-PROD ONLY: run `php artisan db:scrub` before any dev touches it (07 §6.1).
#    The scrub refuses to run if APP_ENV=production.
```

### R6. Rotate a leaked secret

Assume the secret is already compromised the moment it leaked. Speed over tidiness.

```
1. REVOKE at the source first (do not wait to update .env):
   - R2 key      → Cloudflare dashboard: delete the token, mint a new scoped one.
   - DB password → managed PG console: rotate the app-role password.
   - Postmark    → rotate server token.
   - APP_KEY     → generate new; NOTE: rotating APP_KEY invalidates encrypted cookies/sessions
                   (all users logged out) and any encrypted DB columns — plan a re-encrypt if used.
2. Update GitHub Environment secret (production, protected) with the new value.
3. Redeploy so the box picks up the new .env (R2 deploy job writes .env chmod 600).
4. Update the age-encrypted break-glass .env in the separate private repo.
5. Purge the leak from git history if it was committed (git filter-repo) AND treat it as still
   compromised — history rewrite does not un-leak a pushed secret; step 1 is what protects you.
6. Log the rotation in the audit channel; if the secret was in a commit, Gitleaks should have
   blocked it — investigate why it did not (06 §B.1 gate 4).
```

### R7. Respond to a suspected data breach

Passport scans, bank statements, commission money → treat every suspicion as real until disproven.

```
1. CONTAIN (minutes):
   - Flip the relevant Pennant kill-switch (wallet.topup, backup.import, uploads) to OFF —
     no deploy needed.
   - If credential compromise: revoke sessions (php artisan session:flush or targeted revoke),
     rotate affected secrets (R6).
   - If a single actor: suspend the user/agency (revokes their sessions, freezes wallet writes).
2. PRESERVE:
   - Do NOT wipe. Snapshot the DB (PITR marker) and copy relevant auth_events /
     document_access_log rows — they are append-only and are the forensic trail.
   - Capture request_id ranges from logs/Sentry around the window.
3. ASSESS scope:
   - Query document_access_log: who read which document, when (actor, ip, presign mints).
   - Query auth_events for anomalous sign-ins / OTP floods / admin exports.
   - Determine data classes touched (PII vs special-category vs financial).
4. NOTIFY:
   - Escalate to the lead + client product owner immediately (R8).
   - GDPR: special-category data on students bound for UK/EU institutions carries breach-
     notification duties. Record which subjects and which onward recipients (document_disclosures).
5. REMEDIATE + write-up: root cause, fix, and a tracked issue per gap. No blameless-postmortem
   step is skipped even under time pressure.
```

### R8. On-call escalation

Single on-call (the lead); no 24/7 rotation (assumption A5 in [06](06-devsecops-pipeline.md#assumptions-correct-these-if-wrong)).

```
Tier 0  Automated: readiness 503 → nginx drops the container from upstream; Horizon retries jobs;
        AV-infected upload → auto-quarantine. Many issues self-heal with no human.
Tier 1  PAGE (one of the 5 in H.2) → push/call to the lead via Betterstack/ntfy.
        Ack within 15 min. Use the matching runbook (R3 deploy, R5 data, R7 breach).
Tier 2  Lead cannot resolve in 30 min OR it is money/breach → phone the client product owner
        and the original build contractor (contact list pinned in #alerts topic).
Tier 3  Third-party outage (managed PG, R2, Postmark, Cloudflare) → open a provider ticket,
        post status in #alerts, apply the documented degraded-mode (e.g. ClamAV down → uploads
        503 but reads continue). Do not improvise data changes to route around a provider outage.
WARN alerts (everything not in the 5): reviewed next business day from #alerts. Not a page.
```

---

## Open questions

| # | Question |
|---|---|
| 1 | Managed Postgres provider not chosen — PITR window + restore-to-new-instance UX differ (DigitalOcean / RDS Mumbai / Neon / Supabase). The ≤5 min RPO / ≤60 min RTO assume a tier with ≥7-day PITR. |
| 2 | Forge as CD orchestrator vs pure GitHub Actions over SSH — deploy snippets work either way. |
| 3 | Redis managed (~$15/mo, survives box loss) vs on-box MVP — on-box loss = all users logged out on rebuild. Client call. |
| 4 | Log aggregation deferred to journald+logrotate for MVP (Loki/Vector when funded) — confirm no compliance requirement forces centralized tamper-evident retention from day one. |
| 5 | DAST depth: ZAP baseline for the release gate vs a full authenticated active scan (needs seeded staging creds + a maintained context file) — who owns it? |
| 6 | ClamAV as a compose sidecar on the 4 GB box competes with FPM/Redis for ~1–2 GB — validate sizing or move scanning to R2's scan hook / a separate small instance. |
| 7 | Break-glass `age` key holder + recovery process if that person is unavailable. |
