# 07 — Testing strategy

What: how the VFI backend and the existing static frontend are tested, and what "done" means for a phase exit gate. For engineers and AI agents writing/reviewing tests.

Stack: **PHP 8.3 + Laravel 11 + Pest + PostgreSQL 16** backend; **vanilla ES5 HTML/CSS/JS** frontend verified by **headless Chrome over CDP** (no framework — see [memory.md](../memory.md#testing)).

Siblings: [DevSecOps pipeline](06-devsecops-pipeline.md) · [Environments & runbooks](08-environments-and-runbooks.md).

---

## 0. Principles

| # | Principle |
|---|---|
| 1 | **Assert observable outcomes, not absence of errors.** Every real bug in this project was found by checking a thing *actually happened* — a panel opened, a filter returned 2 rows, a balance changed. `try/catch` and green-but-empty tests hide worse bugs. Carried from the frontend harness. |
| 2 | **Money and documents are tested at 100%.** Wallet ledger atomicity, tenant isolation, and document scan-gating get integration tests with real Postgres/Redis/MinIO — never mocks. |
| 3 | **Tenant isolation has a dedicated blocking gate.** A forgotten `WHERE agency_id` leaks a competitor's student book; one test class exists solely to fail on that. |
| 4 | **Never real student PII in non-production.** Passport-adjacent data is scrubbed before any dev sees it (see §6). |
| 5 | **Re-validate every client rule server-side.** The frontend `FILTERS`/`validate*` functions are UX only; the API re-checks max lengths, email regex, 4-digit year, one-decimal score, phone minimum. Tests assert the server rejects what the client would have. |

---

## 1. The pyramid

```
              ▲  e2e / DAST         few, slow, high-value   (~5%)
             ╱ ╲  ── ZAP baseline, critical user journeys
            ╱   ╲ contract          API shape vs frontend   (~10%)
           ╱     ╲ ── store.js HTTP contract, OpenAPI
          ╱       ╲ integration     real PG/Redis/R2/ClamAV  (~30%)
         ╱         ╲ ── money, tenancy, uploads, migrations
        ╱___________╲ unit          pure logic, fast         (~55%)
                       ── DTOs, enums, policies, validators
```

| Layer | Owns | Runner | Target coverage | Speed |
|---|---|---|---|---|
| Unit | pure domain logic: DTOs, PHP enums (the ~8 status vocabularies), policies/gates, validators, money math (NUMERIC), completeness/journey % derivations | Pest, no DB | **≥ 80% of `app/Domain`, `app/Policies`** | ms |
| Integration | anything crossing a boundary: Eloquent + RLS, wallet transactions, document upload→scan→presign, migrations, KPI aggregates, search facets | Pest + real services | **≥ 70% overall (CI `--min=70`)**; **100% of wallet + document + tenancy paths** | seconds |
| Contract | the `js/store.js` HTTP contract + OpenAPI schema the 52 pages depend on | Pest + Spectator/schema assert | every endpoint the frontend calls | seconds |
| e2e / security | critical journeys + OWASP ZAP baseline on staging | CDP scripts + ZAP | the 6 must-pass journeys (§4) | minutes |

Coverage is a floor to catch regressions, **not** a target to game. A 100%-covered wallet with no atomicity test is worthless; the §5/§7 named tests matter more than the number.

---

## 2. Unit tests

Fast, no I/O. What they own:

| Subject | Example assertion |
|---|---|
| PHP enums | `ApplicationStatus::from('offer')` valid; unknown value throws — the 6-value vocabulary is closed |
| Money math | `Money::of('15000.00','USD')->add(...)` never floats; rounding is banker's; NUMERIC in, NUMERIC out |
| Policies/gates | `content_editor` cannot `import`/`reset`/`togglePage`; `finance_viewer` can `wallet.write` |
| Validators | server rejects a 5-digit year, a 2-decimal test score, a `javascript:` URL in `ppQuicklinks.url` |
| Derivations | completeness meter reproduces the frontend's exact 26-item arithmetic (visa pack excluded); journey % = (done + 0.5·now)/total |
| Empty-string contract | a blank override field round-trips as `""` not `null` (the load-bearing "keep the page's built-in HTML" rule) |

---

## 3. Integration tests

Run against the **real** Postgres/Redis/MinIO/ClamAV from CI services (never SQLite, never mocks — RLS, `FOR UPDATE`, and jsonb behave differently). Use `RefreshDatabase` with transactional rollback, except tests that assert commit behaviour (money) which use truncation.

Must-have integration suites:

| Suite | Proves |
|---|---|
| **Wallet ledger** | fee debit is atomic with application insert (no debit without application, none without debit); ledger is append-only (app role has no UPDATE/DELETE grant); `balance == SUM(signed ledger)` invariant; a replayed webhook credits **once** (idempotency key) |
| **Tenancy** (§5) | every partner query carries `agency_id`; RLS returns zero rows if the scope is stripped |
| **Document pipeline** | EICAR file is quarantined and never presignable; upload unreadable until `scan=clean`; presigned GET is single-use + short-lived; every read writes `document_access_log`; client filename never becomes a storage path |
| **Migrations** | `migrate` then `migrate:rollback` on a seeded DB is clean; expand-only migration is safe against N−1 fixtures |
| **Content bundle** | `GET /content/bundle?page=` fall-through: blank override leaves built-in HTML; non-empty overrides; blog `legacy_id` resolves; dates stored as `date` not `timestamptz` |
| **Search facets** | 40-facet combinations return exactly the matching set; negative-requirement flags (Without English/GRE/GMAT/Maths) behave as waivers, not absent rows |
| **Auth** | OTP: wrong code rejected, 6th attempt destroys code, expiry enforced, resend invalidates prior; register/forgot enumeration-safe (uniform response + timing); reset revokes all sessions |

---

## 4. Contract & e2e

### 4.1 Contract tests

The 52 static pages depend on `js/store.js` keeping ~30 identical function names over HTTP. The contract layer guards the **API shape**:

- Every endpoint the frontend calls has an OpenAPI schema; responses are asserted against it (Spectator or a JSON-schema matcher).
- The frontend seam is exercised: `GET /content/bundle` returns the exact shape `window.VFI_BOOTSTRAP` expects, so the synchronous accessors (`list/get/settings/media/country/region`) keep working without a boot-path rewrite.
- Breaking the shape (renaming a field, returning `null` for `""`) **fails contract CI before it reaches the frontend**.

### 4.2 Critical journeys (must-pass e2e)

| # | Journey | Verifies |
|---|---|---|
| 1 | Admin login → TOTP → edit a blog → public page reflects it | admin auth gate + CMS write→read |
| 2 | Student register → OTP → login → upload a document → download via signed URL | auth + scan-gated pipeline |
| 3 | Partner register → staff approve → partner login bound to tenant | tenancy provisioning |
| 4 | Partner A cannot read Partner B's students via any forged id | tenant isolation (negative) |
| 5 | Contact form submit → lead in DB → staff inbox | the P0 "stop dropping leads" |
| 6 | Wallet top-up webhook credits once; fee debit atomic with application | money correctness |

### 4.3 Frontend checks (existing CDP harness)

The frontend has **no test framework** — verification is headless Chrome over CDP (Node 24, WebSocket, no puppeteer). It stays as-is and runs **alongside** the API tests, not replaced by them. Per [memory.md](../memory.md#testing), standard per-page checks:

- horizontal overflow at 1440 and 390 (compare `scrollWidth` vs `documentElement.clientWidth`, not `innerWidth`)
- unresolved `<use href="#…">`, elements stuck at `opacity:0`, shadow-cutting clip-paths
- `Runtime.exceptionThrown`, exactly one `<h1>`, header+footer present (except the 6 chrome-less auth pages + 11 `partner-*.html` console pages — keep the `NO_CHROME` list in sync)
- drive char-restriction / OTP tests with real CDP key events (`rawKeyDown` + `char`, never `keyDown` *with* text — double-inserts)

Landmines that bite the harness (all in [memory.md](../memory.md#landmines--these-have-all-bitten-before)): a reused `--user-data-dir` caches JS on disk → send `Network.setCacheDisabled{true}`; reveal animations need ~1600 ms to settle; **do not `fetch()` the server in a loop while a CDP socket is open** (undici assertion crashes the harness) — use `fs.existsSync` for file checks.

**Division of labour:** API/integration tests own data correctness and security; the CDP harness owns rendering, layout, and that the wired frontend actually shows what the API returns. As each `REAL REQUEST` point goes live behind its flag, add a CDP check that the page renders real data and the demo disclaimer is gone.

---

## 5. Security tests

Security is embedded per layer, not a terminal phase. Each attack has a **negative test** that must fail (the attack does not work):

| Control | Test asserts |
|---|---|
| Tenant isolation | `TenancyGuardTest`: partner query contains `"agency_id" ="`; stripping the Eloquent scope returns **zero rows** via Postgres RLS, never another agency's data |
| Admin auth | any `/api/admin/*` route without a session → 401/redirect, renders no data; password without TOTP cannot reach `/panel` |
| OTP hardening | any six digits is **rejected**; brute-force + amplification are server-rate-limited (survives page reload, unlike client cooldowns) |
| Email-change takeover | attacker who knows a pending applicant's email cannot redirect the code without the server-side `flow_id` |
| IDOR | no student/partner endpoint accepts an id from client input; enumerating `student_ref`/`VFI-2026-xxxxx` yields nothing |
| URL scheme | `javascript:`/`data:` in any admin-authored URL field is refused server-side; blog body stored/rendered as plain text (anti-stored-XSS contract) |
| CSRF | cross-site state-changing request without the double-submit token is rejected |
| Upload | wrong magic bytes (renamed `.exe`/`.svg`) rejected regardless of extension; oversize rejected |
| Authz split | `content_editor` provably cannot `import`/`reset`/toggle pages; money writes require `owner`/`finance_viewer` + step-up |

Plus the pipeline gates in [06 §B.1](06-devsecops-pipeline.md#b1-gate-matrix--block-vs-warn): Gitleaks, Semgrep, Trivy, Checkov, and the ZAP baseline that gates promote-to-prod.

---

## 6. Test data management

**Rule: never real student PII in local/dev/staging.** Passport scans, transcripts, bank statements, medical/police clearances are the highest-value target in the product.

| Environment | Data source |
|---|---|
| local | seeded demo (`VFI.SEED` importer) + factory-generated synthetic rows. Mail → Mailpit, files → MinIO. Nothing real. |
| dev | seeded + synthetic; same as local at cloud scale |
| staging | **prod-shaped clone, PII-scrubbed** — the only env that mirrors prod topology (managed PG, R2, real ClamAV) so restore drills and DAST are meaningful |
| production | real |

### 6.1 Scrub (post-restore, refuses to run if `APP_ENV=production`)

```sql
-- artisan db:scrub
UPDATE users     SET email = 'user'||id||'@example.test', password = '<argon2 of "password">';
UPDATE students  SET first_name='Test', last_name='Student'||id, phone_national='0000000000';
UPDATE documents SET storage_key = 'scrubbed/'||id;      -- detach from real private blobs
TRUNCATE auth_events, document_access_log;               -- no real access history in staging
-- wallet balances kept (shape matters); agency legal_name pseudonymised
```

Document *bytes* are never copied to non-prod — `storage_key` is rewritten to a scrubbed prefix so no signed URL can resolve to a real passport. Restore + scrub procedure: [08 §R5](08-environments-and-runbooks.md#r5-restore-from-backup).

### 6.2 Factories & fixtures

- Laravel model factories generate realistic anonymised rows (faker with BD-appropriate names/phones/dial codes). Deterministic seeds so a failing test reproduces.
- Shared helpers: `actingAsPartner($agency)` (sets session agency + RLS GUC), `actingAsStudent($student)`, `actingAsAdmin($role)`, `seedApplicationsFor($agency, $n)`.
- Money fixtures use explicit NUMERIC strings, never float literals.
- A synthetic partner-table fixture exists purely for the tenancy guard — it must never contain data resembling a real agency.

---

## 7. Flake policy

Flaky tests erode the whole suite's authority. Policy:

| Rule | Detail |
|---|---|
| Zero-tolerance on `main` | a test that fails intermittently is **quarantined within 24h** (tagged `@flaky`, excluded from the blocking gate) and gets a tracked issue; it does not sit red. |
| Fix or delete in one sprint | a quarantined test is fixed or deleted the next sprint — a permanently-skipped test is a lie about coverage. |
| No `sleep()` waits | poll for a condition (job processed, row present), never a fixed sleep. The one documented exception is the CDP harness's ~1600 ms reveal-settle, which is a real animation timing, not a race. |
| Deterministic clocks/ids | freeze time (`Carbon::setTestNow`) and seed faker; no test depends on wall-clock or random ordering. |
| Real services, isolated state | integration tests use `RefreshDatabase`/truncation so order-independence holds; never share MinIO keys across tests. |
| Retry is a smell, not a fix | CI does **not** auto-retry failed tests to green. A retry masks the race the test was meant to catch (Principle 1). |

---

## 8. Definition of done — phase exit gate

A phase is **DONE** only when all of the following hold. This is the objective gate the roadmap references; "looks done" is not a state.

| # | Criterion |
|---|---|
| 1 | Every exit-gate criterion for the phase is demonstrably true in **staging**, verified by re-running the named tests green in CI. |
| 2 | The phase's security work is present **and proven by a negative test** — the attack it prevents actually fails (§5). |
| 3 | A live demo of the phase's demonstrable outcome is walked through end-to-end against staging. |
| 4 | No **BLOCK-class** CI gate is red: secret scan, fixable Critical/High CVE, SAST-high, the untenanted-query tenancy test, denied licenses. WARN-class findings are logged as tracked issues, not blocking. |
| 5 | Coverage floors hold: overall `--min=70`; wallet + document + tenancy paths at 100% of their branches. |
| 6 | Expand/contract migration discipline verified: every migration in the release is safe against N−1 code (code rollback is DB-safe). |
| 7 | Two-party sign-off: the Architecture/DevSecOps lead signs the security + exit-gate criteria; the client product owner signs the demonstrable outcome. |

Hard gates that **cannot be waived or carried as debt**: the admin-auth criterion (Phase 1) and the tenancy-isolation criterion (Phase 6). Promotion to production is a **separate** gated job (DAST baseline green + backup-restore drill current), distinct from merge-to-main — see [06 §C.1](06-devsecops-pipeline.md#c1-branching--trunk-ish-github-flow-with-a-staging-trunk).
