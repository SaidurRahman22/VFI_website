# 05 — Security Architecture, Threat Model & Compliance

One-line: the security design, STRIDE threat model, authz matrix, upload pipeline, ASVS control list, secrets/audit rules and compliance posture for the VFI backend — written for the engineers and agents building it.

**Status:** Design baseline for implementation.
**Stack:** Laravel 11 + Filament v4 + managed PostgreSQL 16 + Redis + Cloudflare R2, deployed **same-origin** behind one nginx. See [ADR / stack decision](00-architecture.md) and the [roadmap](02-roadmap.md) for phase definitions (P0–P9).
**Data at stake:** passport bio pages, academic transcripts, 6-month bank statements, sponsor affidavits, visa forms (DS-160/UKVI/IRCC), medical/police clearances, partner commission money, a prepaid wallet ledger.
**Sibling docs:** [Data model](03-data-model.md) · [API contract](04-api-contract.md) · [Auth & tenancy](06-auth-and-tenancy.md).

> **Reading key.** `[ASSUMPTION]` marks a decision not fixed by the brief that the team must confirm (see the register at the end). `P0`…`P9` are roadmap phases. Line references (`js/auth.js:738`) point at the current static front end. British spelling throughout (organise, colour, minimise).

---

## 0. Security invariants — the seven things that must always be true

| # | Invariant | Enforced by | Lands |
|---|-----------|-------------|-------|
| I1 | No admin content endpoint is reachable without an authenticated admin session **and** TOTP. | `/api/admin/*` middleware `auth:ad` + `mfa.enrolled` | P1 |
| I2 | A partner can never read or write another agency's row. | Eloquent `BelongsToAgency` global scope **+** Postgres RLS **+** CI test | P1 (net) / P6 (live) |
| I3 | The acting `agency_id` / `student_id` is derived from the session, never from a request parameter. | Resolver reads `Auth::user()`; request DTOs have no tenant/owner id field | P4/P6 |
| I4 | No document byte is readable by anyone until ClamAV returns clean. | `documents.av_scan_status='clean'` gate on every presign | P5 |
| I5 | A password, OTP plaintext, session token, or document body never appears in a log, a URL, or an error. | Log scrubber + `flow_id` (not `?email=`) + `Cache-Control: no-store` | P1/P4 |
| I6 | Money mutations are append-only, atomic with their business event, and idempotent. | `SELECT … FOR UPDATE` + unique idempotency key + stored `balance_after` | P9 |
| I7 | Empty string `""` / `[]` round-trips faithfully (means "keep the page's built-in HTML"). | `ConvertEmptyStringsToNull` + `TrimStrings` **disabled** on content routes | P0/P2 |

I7 is a correctness trap, not a threat — but breaking it silently blanks 32 public pages, so it lives with the invariants.

---

## 1. Trust boundaries & topology

```text
                        Cloudflare (WAF, TLS, Turnstile, cache)
                                     |  HTTPS/HSTS
                          +----------v-----------+
                          |   nginx (one origin) |
                          |  static files + /api |
                          +---+---------------+---+
         same-origin, no CORS |               | PHP-FPM (fpm-web pool, fpm-upload pool)
                    +---------v-----+   +------v-------------------------+
   Cookie scopes -> | 52 static HTML|   | Laravel API + Filament admin   |
   sa_ / pp_ / ad_  | (ES5, no auth)|   |  Policies . Sanctum . Horizon  |
                    +---------------+   +--+----------+---------+--------+
                                           |          |         |
                              +------------v+  +------v----+ +--v----------+
                              | PostgreSQL16 |  |  Redis    | | Cloudflare  |
                              | (managed,    |  | sessions/ | | R2          |
                              |  PITR, RLS)  |  | rl / queue| | pub + priv  |
                              +--------------+  +-----------+ +--+----------+
                                                      |          | ClamAV scan-gate
                                    +-----------------v--+   +---v------------+
                                    | Postmark/SES (mail)|   | PSP webhooks   |
                                    | SPF/DKIM/DMARC     |   | (bKash/SSLCz)  |
                                    +--------------------+   +----------------+
```

### The five boundaries the threat model crosses

| ID | Boundary | Untrusted side | Trusted side | Primary control |
|----|----------|----------------|--------------|-----------------|
| **TB1** | Anonymous internet → API | Any browser/bot | Laravel request lifecycle | Turnstile, rate limits, input validation, CSRF |
| **TB2** | Student → own data | Authenticated student (`sa_` cookie) | Student-owned rows only | `student_id = auth()->id()`, policies, RLS |
| **TB3** | Partner agency → own tenant | Authenticated agency user (`pp_` cookie) | One `agency_id` | Global scope + RLS + tenant CI test |
| **TB4** | Admin/staff → everything | Authenticated staff (`ad_` cookie, TOTP) | All tenants, deliberately | Path prefix `/api/admin`, role gates, step-up, audit |
| **TB5** | App service → storage / DB / mail / PSP | The app process, queue workers | R2, Postgres, providers | Least-privilege creds, scan-gate, signed URLs, webhook signature verification |

**Why same-origin.** It removes CORS, lets the session cookie be `SameSite=Lax` (never `SameSite=None`), and keeps the token out of `localStorage` — decisive given the existing `innerHTML` XSS surface (`js/portal.js` `VFIToast`, several `render.js`/`portal-render.js` innerHTML writers). An `HttpOnly` cookie is not reachable from JS, so XSS cannot exfiltrate the session. Reversal cost: moving the static site to a different registrable domain later forces `SameSite=None` and a weaker posture — keep it same-origin.

---

## 2. STRIDE threat model

Severity = Likelihood × Impact, each 1–5, mapped to **Low / Med / High / Critical**. Every row carries the asset at risk, the mitigation, and the roadmap phase the mitigation lands in. ASVS tags cross-reference §6.

### TB1 — Anonymous internet → API

| STRIDE | Threat | Asset | Likelihood | Impact | Sev | Mitigation | Phase | ASVS |
|--------|--------|-------|:---:|:---:|-----|------------|:---:|------|
| **S** | Register on a victim's email; verify is skippable, any 6 digits pass (`js/auth.js:1137`). | Account integrity | 4 | 3 | High | OTP mandatory before privileged actions; account stays `pending_verification`; enumeration-safe register. | P4 | 2.1, 2.2 |
| **S** | **Partner email-change takeover** — verify page re-points OTP to any typed address (`partner-auth.js:1364`). | Agency account | 3 | 5 | **Crit** | OTP bound to server `flow_id` not client email; change needs `flow_id`, restarts flow, invalidates prior codes, cap 2/registration. | P6 | 2.5.4 |
| **T** | Wizard steps 1–2 validated client-side only; forged payload to `/api/partner/register`. | Registration data | 4 | 2 | Med | Server re-validates every field; client FILTERS are UX only. | P6 | 5.1 |
| **T** | `javascript:` URL injected into `ppQuicklinks.url` / `ppDocs.url` / `services_blocks.ctaHref` → stored XSS on click. | Site visitors | 3 | 4 | High | Server-side scheme allow-list (`http/https/mailto/relative`) on save **and** render. | P2/P3 | 5.3.3 |
| **T** | HTML injected into a blog body becomes stored XSS (contract is plain-text). | Site visitors | 2 | 4 | Med | Keep blog body plain-text end to end; server rejects HTML; no rich-text editor. | P2/P3 | 5.3 |
| **R** | Bot floods contact form / registration; no trace. | Lead inbox, mail reputation | 4 | 2 | Med | `auth_events` append-only, `contact_enquiries.ip`, server-verified Turnstile token. | P2/P4 | 7.1 |
| **I** | Email enumeration on register / sign-in / forgot. | Customer list | 4 | 2 | High | Uniform response + uniform timing (dummy argon2 hash on unknown user); forgot always `202`. | P4/P6 | 2.2.1 |
| **I** | PII in URL (`?email=`) leaks to history, Referer, proxy logs. | Student PII | 4 | 3 | High | Replace with opaque `flow_id`; keep `maskEmail()` for display only. | P4/P6 | 8.3.1 |
| **D** | OTP endpoints = free email amplification; verify = brute-force (10^6 over 10 min). | Mail spend, account | 4 | 3 | High | Server rate limits (§3.2); hashed code; 5-attempt destroy; 3/hr send cap; Turnstile. | P4 | 2.2.1, 11 |
| **D** | Program search (40 facets, ~600k rows) as an unauthenticated CPU sink. | DB / app CPU | 3 | 3 | Med | Search requires partner session; per-user rate limit; `statement_timeout=3s`. | P8 | 11.1 |
| **E** | Guessing `/admin.html` → full destructive CRUD (today: no login at all). | Whole CMS | 5 | 5 | **Crit** | **P0** admin behind `auth:ad`+TOTP; UI not at web root; nginx allow-list on `/api/admin`. | P1 | 1.2, 4.1 |

### TB2 — Student → own data

| STRIDE | Threat | Asset | Likelihood | Impact | Sev | Mitigation | Phase | ASVS |
|--------|--------|-------|:---:|:---:|-----|------------|:---:|------|
| **S** | Session fixation / cookie replay after logout. | Student session | 2 | 4 | Med | Rotate session id on login; server-side revoke on logout; `HttpOnly; Secure; SameSite=Lax`. | P4/P5 | 3.2, 3.3 |
| **T** | IDOR — `/api/students/{id}` for another student; `VFI-2026-04871` is sequential/guessable. | All student files | 4 | 5 | High | **No id in path** — self endpoints `/api/me/*`; `student_ref` never an access key; `StudentPolicy::own`. | P5 | 4.2.1 |
| **T** | Student edits legal name/DOB after application submitted → visa mismatch. | Application integrity | 3 | 3 | Med | Locked fields become staff-approved change-requests once any application ≠ draft. | P5 | 4.3 |
| **R** | Student denies uploading a forged document. | Dispute defence | 2 | 3 | Med | `document_status_history` + `document_access_log` (actor, sha256, time). | P5 | 7.1 |
| **I** | Profile persisted unencrypted in `localStorage` on a shared/cyber-café machine (current behaviour). | Student PII | 4 | 4 | High | **Stop client-side profile persistence**; data lives server-side, fetched per session; `no-store` on authed responses. | P5 | 8.2.2 |
| **D** | Oversized multi-MB phone scans exhaust the upload worker. | Availability | 3 | 3 | Med | Size cap (§5); dedicated `fpm-upload` pool; per-student total quota. | P5 | 12.1 |
| **E** | Student calls a partner/staff endpoint. | Cross-actor data | 3 | 4 | High | Route scoped to `auth:student`; role gate; default-deny policies. | P4/P5 | 4.1 |

### TB3 — Partner agency → own tenant (highest-value confidentiality boundary)

| STRIDE | Threat | Asset | Likelihood | Impact | Sev | Mitigation | Phase | ASVS |
|--------|--------|-------|:---:|:---:|-----|------------|:---:|------|
| **T** | `?agencyId=` / body `agency_id` reads another agency's students, wallet, documents. | Competitor's book | 4 | 5 | **Crit** | `agency_id` from session only; `BelongsToAgency` scope; **RLS** returns 0 rows on a forgotten `WHERE`; CI test fails on untenanted partner query. | P6 | 4.2, 1.4 |
| **T** | Referral/QR self-registration farms student attributions (→ commission). | Commission integrity | 3 | 4 | High | Opaque unguessable slug, revocable, `max_uses`, per-slug rate limit, Turnstile, email-verify before attribution counts. | P7 | 4.2 |
| **T** | Wallet balance manipulated via client-supplied amount. | Money | 3 | 5 | **Crit** | Amounts never trusted from client; balance server-derived; append-only ledger with `balance_after`; `FOR UPDATE` + idempotency key. | P9 | 4.3 |
| **R** | Partner disputes a fee debit / top-up. | Dispute defence | 3 | 3 | Med | `wallet_transactions` immutable; `payment_provider_events` raw envelope; `audit_log` before/after. | P9 | 7.1 |
| **I** | Regional-manager phone/email + student book readable because it sits in one public `localStorage` blob today. | Staff/student PII | 4 | 3 | High | Console content behind `auth:partner`; managers served per-session, not global. | P6/P7 | 4.2 |
| **I** | Seat/counsellor cross-read within an agency. | Intra-agency data | 2 | 2 | Low | `agency_id` scope + `seat_role` gate on finance/wallet writes. | P6 | 4.2 |
| **E** | Counsellor performs owner-only wallet write. | Money | 3 | 4 | High | `seat_role in (owner, finance_viewer)` gate + step-up re-auth on money-out. | P9 | 4.1 |
| **S** | One shared agency login (wizard makes one password) → no per-user audit. | Accountability | 4 | 2 | Med | Model `partner_agency_members` from day one; invite flow later; audit keyed to `user_id`. | P6 | 3.1 |

### TB4 — Admin / staff → everything

| STRIDE | Threat | Asset | Likelihood | Impact | Sev | Mitigation | Phase | ASVS |
|--------|--------|-------|:---:|:---:|-----|------------|:---:|------|
| **S** | Admin credential phished; no MFA today. | Whole system | 4 | 5 | **Crit** | Mandatory TOTP; invite-only accounts (no self-signup); 15-min idle session. | P1 | 2.8 |
| **T** | `importAll()` = one request replaces the whole site (only checks `content` exists). | Whole CMS | 3 | 5 | **Crit** | Owner-only + step-up TOTP; strict schema validation; size cap; auto pre-restore snapshot; two-step diff preview; audit. | P3 | 12.1, 1.2 |
| **T** | Page kill-switch disables `login.html` / partner login → business DoS. | Availability | 3 | 4 | High | Toggling is privileged + audited; sign-in entries marked "always on"; edge-enforced if used to hide. | P3 | 4.1 |
| **R** | Anonymous destructive edit; every save is unattributed today. | Recovery | 4 | 3 | High | `content_audit_log` (actor, action, before/after, ip); soft-delete on collections. | P3 | 7.1 |
| **I** | Backup export dumps student PII + partner financials. | All PII + money | 3 | 5 | **Crit** | Superadmin-only, TOTP step-up, rate-limited, email-notified, audited; excludes documents + credentials; signed expiring link. | P3/P9 | 8.1 |
| **E** | Content editor gains partner-ops / finance rights (one god-mode today). | Privilege split | 3 | 4 | High | Split roles (`content_editor` ≠ `staff_partner_ops` ≠ `finance` ≠ `superadmin`); Filament policies per resource. | P1/P3 | 4.1 |

### TB5 — Service → storage / DB / providers

| STRIDE | Threat | Asset | Likelihood | Impact | Sev | Mitigation | Phase | ASVS |
|--------|--------|-------|:---:|:---:|-----|------------|:---:|------|
| **S** | Forged PSP / Flywire webhook credits the wallet. | Money | 3 | 5 | **Crit** | Verify provider signature; dedupe on `provider_event_id`; credit only inside a DB tx from the verified event, never from browser callback. | P9 | 5.1 |
| **T** | Malware uploaded as "passport.pdf", served to staff. | Endpoints, integrity | 3 | 4 | High | Magic-byte sniff + ClamAV scan-gate before any presign; private bucket. | P5 | 12.2, 12.3 |
| **R** | "Who opened whose passport?" unanswerable. | Compliance | 3 | 4 | High | `document_access_log` on every presigned-URL mint (append-only). | P5 | 7.1 |
| **I** | Over-broad R2 / DB credentials leak the lot. | Everything | 2 | 5 | High | Least privilege: separate R2 token per bucket; DB app-role without `DROP`; secrets in a manager, not `.env` in repo. | P0 | 6.4, 2.10 |
| **T** | Bad migration on deploy races multiple replicas. | Availability, data | 2 | 4 | Med | Migrations as one-shot deploy job with advisory lock; never on app start. | P0 | 14.1 |
| **D** | AV scan queue starves OTP mail. | Deliverability | 3 | 3 | Med | Four named Horizon queues (`emails`, `documents`, `search`, `default`). | P0/P5 | 12.1 |

---

## 3. Authentication & authorisation

### 3.1 Password hashing

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| Algorithm | **argon2id** | `config/hashing.php driver=argon2id`; memory-hard, side-channel resistant. |
| memory_cost | **65536 KiB (64 MiB)** | Above OWASP floor (19 MiB); tune down only under PHP-FPM RSS pressure. |
| time_cost | **3** | — |
| threads | **1** | PHP-FPM is single-thread-per-worker; >1 gains nothing. |
| Fallback | bcrypt cost ≥ 12 | Only if libsodium/argon2 unavailable at runtime. |
| Pepper | `[ASSUMPTION: optional]` app-level HMAC pepper from secrets manager, applied before hash | Defends a stolen DB without the app secret. Adds rotation cost — decide explicitly. |
| Policy | min 8, **no max < 64**, no composition rules, breach-list check | Matches client rule (`js/auth.js:158`); HIBP k-anonymity range or local top-100k list. Strength meters stay **advisory**, never gate server-side. |
| Timing | Dummy hash on unknown account | Unknown-user and wrong-password take equal time. |

```php
// config/hashing.php
'argon' => ['memory' => 65536, 'threads' => 1, 'time' => 3],

// Unknown-user constant time
$user = User::where('email', $email)->first();
Hash::check($password, $user?->password_hash ?? self::DUMMY_ARGON2ID_HASH);
```

### 3.2 OTP + rate limits

**One code table for both actor families**, keyed on an opaque `flow_id` + pending email — never `user_id` alone, because verify pages allow changing the destination address mid-flow.

```sql
CREATE TABLE email_verification_codes (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  flow_id       text NOT NULL UNIQUE,           -- replaces ?email= in URLs
  user_id       uuid REFERENCES users(id),      -- nullable during signup
  email         citext NOT NULL,
  code_hash     bytea NOT NULL,                 -- sha256(code || flow_pepper); never plaintext
  purpose       text NOT NULL CHECK (purpose IN
                  ('signup_student','signup_partner','email_change')),
  created_at    timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,           -- created_at + interval '10 minutes'
  attempts_used smallint NOT NULL DEFAULT 0,    -- destroy at 5
  consumed_at   timestamptz,
  request_ip    inet
);
CREATE INDEX ON email_verification_codes (flow_id) WHERE consumed_at IS NULL;
```

| Control | Value |
|---------|-------|
| Code | 6 digits, CSPRNG (`random_int(0,999999)`), zero-padded |
| Storage | `sha256(code)` (or HMAC with `flow_pepper`), constant-time compare |
| TTL | 10 min (UI already promises this) |
| Verify attempts | 5 → code destroyed, flow must resend |
| Send cap | ≤ 3 per email per hour; hard 30s min between sends (mirrors client cooldown) |
| Resend | Invalidates the previous code (UI already claims it does) |
| Consumption | Single-use; set `consumed_at` in the same tx that verifies |
| Backend | Redis counters (`otp_send:email:x`, `verify:flow:z`), not Postgres |

**Rate-limit matrix (Redis, `RateLimiter::attempt`):**

| Endpoint | Per-identifier | Per-IP | Escalation |
|----------|----------------|--------|------------|
| `POST /api/*/signin` | 10 fails → 15-min lock (progressive delay from 5) | 30/min | per-ASN cap |
| `POST /api/*/register` | 5/hr/email | 10/hr/IP | Turnstile always |
| `POST /api/*/password/forgot` | 3/hr/email, always `202` | 10/hr/IP | — |
| `POST /api/*/verify` (check code) | 5/flow then destroy | 60/min/IP | — |
| `POST /api/*/verify/resend` | 3/hr/email, 30s min | 20/hr/IP | — |
| `POST /api/partner/email/change` | 2/registration | — | restarts flow |

Lockout is **per-account AND independent per-IP/ASN** so an attacker cannot weaponise lockout to deny a real user (never lock solely on username).

### 3.3 Sessions vs JWT — opaque server-side sessions (Sanctum SPA/cookie mode)

Decision: **cookie session, not JWT.** Reasons specific to this codebase: no build step / no JWT lib on the ES5 front end; the `innerHTML` XSS surface means any JS-readable token is exfiltratable; cookies give real logout + real revocation (JWTs do not); same-origin makes `SameSite=Lax` sufficient.

| Property | Value |
|----------|-------|
| Transport | `HttpOnly; Secure; SameSite=Lax` cookie |
| Store | Redis (`SESSION_DRIVER=redis`), server-side, revocable |
| Cookie scopes | **three** — `sa_session` (student), `pp_session` (partner), `ad_session` (admin, `Path=/api/admin`) |
| CSRF | Double-submit: Sanctum `XSRF-TOKEN` cookie + `X-XSRF-TOKEN` header via new `js/api.js`; plus `Sec-Fetch-Site` check |
| Lifetimes | Student/partner 30 min idle / 12 h absolute; **remember-me** (the two dead checkboxes) 12 h idle / 7 d absolute; **admin 15 min idle / 8 h absolute** |
| Rotation | Session id rotated on privilege change (login, TOTP pass, password change) |
| Revocation triggers | password change/reset, role grant/revoke, agency suspension, seat removal, admin revoke, "sign out everywhere" |

`[ASSUMPTION]` No mobile/native client is planned. If one appears, issue Sanctum personal-access tokens for that client only, keeping cookie mode for the web SPA. Sanctum SPA cookie mode has no separate refresh token — the server session *is* the durable credential and is rotated/revoked server-side. If the topology is later forced cross-origin, switch to a memory-only access token (10–15 min) + `HttpOnly` refresh cookie **with reuse detection** (revoke the whole session family on a replayed refresh).

### 3.4 RBAC — roles

```sql
CREATE TABLE user_roles (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL REFERENCES users(id),
  role              text NOT NULL CHECK (role IN (
                      'student','partner_owner','partner_counsellor','finance_viewer',
                      'staff_counsellor','staff_partner_ops','finance','content_editor','superadmin')),
  partner_agency_id uuid REFERENCES partner_agencies(id),  -- NOT NULL for partner_* / finance_viewer
  granted_by        uuid REFERENCES users(id),
  granted_at        timestamptz NOT NULL DEFAULT now(),
  revoked_at        timestamptz,
  CHECK ( (role LIKE 'partner_%' OR role = 'finance_viewer') = (partner_agency_id IS NOT NULL) )
);
```

### 3.5 Full role × resource permission matrix

R = read, W = write, — = none, ⚠ = step-up TOTP required, `own` = self only, `tenant` = own agency only.

| Resource | anon | student | partner_counsellor | partner_owner | finance_viewer | staff_counsellor | staff_partner_ops | finance | content_editor | superadmin |
|----------|:----:|:------:|:-----------------:|:------------:|:-------------:|:---------------:|:----------------:|:-------:|:-------------:|:----------:|
| Public marketing content | R | R | R | R | R | R | R | R | RW | RW |
| Admin: collections CRUD | — | — | — | — | — | — | — | — | RW | RW |
| Admin: backup export/import | — | — | — | — | — | — | — | — | — | RW ⚠ |
| Admin: page on/off | — | — | — | — | — | — | — | — | — | RW ⚠ |
| Admin: user/role mgmt | — | — | — | — | — | — | — | — | — | RW ⚠ |
| Own student profile | — | RW `own` | — | — | — | R (any) | — | — | — | R |
| Own documents (up/download) | — | RW `own` | — | — | — | R (any)+log | — | — | — | R+log |
| Document verify/reject | — | — | — | — | — | RW | — | — | — | RW |
| Partner: students/apps | — | — | RW `tenant` | RW `tenant` | R `tenant` | R (any) | RW (any) | R | — | R |
| Partner: wallet read | — | — | R `tenant` | R `tenant` | R `tenant` | — | R | R | — | R |
| Partner: wallet top-up/pay | — | — | — | RW ⚠ `tenant` | RW ⚠ `tenant` | — | — | R | — | — |
| Wallet refund/adjust | — | — | — | — | — | — | — | RW ⚠ | — | R |
| Approve agency registration | — | — | — | — | — | — | RW | — | — | RW |
| Contact-enquiry inbox | — | — | — | — | — | R | R | — | R | R |
| Audit logs | — | — | — | — | — | — | — | R | — | R |

Enforcement chain: route middleware (`auth:{scope}`) → Laravel **Policy** (`can:`) → Eloquent global scope / RLS (tenant) → step-up middleware (`⚠`). **Default-deny:** a resource with no policy method returns 403.

---

## 4. Securing the admin panel — P0

**Today:** `admin.html` loads `store.js` + `admin.js` and renders full destructive CRUD with **zero authentication** (`admin.html:129` states this in its own UI). Wiring it to a real API without auth would publish an unauthenticated write endpoint over the entire content database — strictly worse than the localStorage demo. This is the single highest-severity gap in the product.

### 4.1 Layered lockdown (build in order; all before any admin write endpoint is live)

1. **Network layer.** Filament admin **not at web root** — served under `/api/admin` behind nginx; `[ASSUMPTION]` add an office/VPN IP allow-list (`allow …; deny all;`) on that location. `noindex` stays but is **not** access control.
2. **Authentication.** Filament auth guard `ad` (Sanctum cookie scope, `Path=/api/admin`). No self-signup route — accounts created only via `admin_invites` (single-use, expiring, superadmin-issued); superadmin bootstrap via a sealed Artisan command; last superadmin cannot demote/delete itself.
3. **MFA — mandatory TOTP** for every admin/staff role (RFC 6238). Enforce enrolment on first login before any resource loads.
4. **Idle timeout** 15 min; absolute 8 h; session rotates on TOTP pass.
5. **Authorisation.** Filament Policies per resource implement §3.5; `content_editor` cannot see partner/student/wallet resources.
6. **Step-up (`⚠`)** re-auth (fresh TOTP) for: backup import/export, page toggle, role grant/revoke, wallet refund/adjust.
7. **Audit.** Every create/update/delete/reorder/import/reset/toggle writes `content_audit_log` (actor, action, entity, before/after JSON, ip). Soft-delete on all collections.
8. **Dangerous ops hardened.**
   - `importBackup`: superadmin+TOTP, JSON schema validation, size cap `[ASSUMPTION: 50 MB]`, automatic pre-restore snapshot, two-step diff preview, audit.
   - `resetToSeedContent`: superadmin+TOTP, snapshot-first; **removed from the production build** (demo affordance).
   - `exportBackup`: superadmin+TOTP, rate-limited, email-notified, signed expiring owner-only link; excludes documents + credentials.
   - Page toggle: privileged + audited; `login.html` and partner login marked "always on".

```php
// routes/admin.php
Route::prefix('api/admin')
  ->middleware(['auth:ad', 'mfa.enrolled', 'admin.idle:15'])
  ->group(function () {
      Route::post('backup/import', [BackupController::class, 'import'])
           ->middleware(['can:superadmin', 'mfa.stepup']);
  });
```

### 4.2 Milestone-1 deliverable (P1, weeks 1–3)

Admin authentication + contact-form persistence. A five-month backend is the wrong answer to a live this-week emergency (no admin login; the contact form silently discards real leads today, `js/main.js:~421`).

---

## 5. Secure document-upload pipeline (passports, bank statements, transcripts)

**Today:** the File object is discarded; only `f.name` is recorded; student inputs have **no `accept` attribute**; no bytes are read. The whole path is build-not-wire (`js/student-portal.js:600-611`).

### 5.1 Pipeline end to end

```text
Browser --multipart--> POST /api/me/documents/{slot}   (fpm-upload pool)
   | 1. auth:student + policy own + CSRF
   | 2. size cap (reject > limit BEFORE buffering fully; nginx client_max_body_size matches)
   | 3. MIME sniff: magic bytes, NOT the client Content-Type / extension
   | 4. write to R2 PRIVATE bucket, key = documents/{uuid}   (server-generated, no user path)
   | 5. row: status=uploaded, av_scan_status=pending, sha256, byte_size
   | 6. dispatch ScanDocument job (queue: documents)
   v
ClamAV (clamd) -- clean ----> av_scan_status=clean  -> presign allowed
              +- infected --> quarantine key, delete object, alert, status=infected
```

### 5.2 Validation rules

| Check | Value | Enforcement |
|-------|-------|-------------|
| Allowed types | `application/pdf`, `image/jpeg`, `image/png` | **magic-byte** (`finfo` / `league/mime-type-detection`), not extension |
| Max size | images 10 MB, PDF 20 MB `[ASSUMPTION]` | rejected pre-buffer |
| Per-student total | e.g. 200 MB `[ASSUMPTION]` | counter check |
| Filename | stored as metadata only, `esc()`-ed; **never** used to build a key | UUID key `documents/{uuid}` |
| Path traversal | impossible — no user segment in key | — |
| Image re-encode | JPEG canvas downscale re-run server-side (strips EXIF/GPS); **PDFs never re-encoded** | `intervention/image` for raster only |
| Transparency | PNG→JPEG flatten only for content images, not documents | — |

### 5.3 Storage, access, encryption

| Concern | Control |
|---------|---------|
| Location | R2 **private** bucket, `documents/` prefix, outside web root, no public URL ever |
| Encryption at rest | R2 SSE (AES-256) **plus** `[ASSUMPTION]` app-level envelope encryption for passport/financial blobs with a KMS-managed data key if the client requires customer-managed keys |
| Download | Per-request **presigned GET, 60–300 s, single-use intent**; issued only if `av_scan_status='clean'` AND caller passes policy |
| Access log | Every presign mint → `document_access_log` (actor, role, acting_agency, doc_id, action, ip, ua) — append-only |
| No-store | `Cache-Control: no-store` on the API response carrying the URL |
| Reference counting | Delete checks no other row/media slot references the same object first (SEED reuses `assets/img/*.jpg` paths — blind port would delete shared assets); `delImage` on a path-style id is a no-op, never touches a static file |
| Retention | `retention_expires_at` per document; scheduled deletion job (§9.4) |

```sql
CREATE TABLE documents (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id        uuid NOT NULL REFERENCES students(id),
  partner_agency_id uuid REFERENCES partner_agencies(id),   -- denormalised tenant col
  pack              text NOT NULL CHECK (pack IN ('application','visa')),
  slot_key          text NOT NULL,
  storage_key       text NOT NULL,                           -- documents/{uuid}, server-generated
  original_filename text,
  content_type      text NOT NULL,
  byte_size         bigint NOT NULL,
  sha256            bytea NOT NULL,
  status            text NOT NULL DEFAULT 'missing'
                      CHECK (status IN ('missing','uploaded','verified','rejected')),
  av_scan_status    text NOT NULL DEFAULT 'pending'
                      CHECK (av_scan_status IN ('pending','clean','infected')),
  av_scanned_at     timestamptz,
  uploaded_by       uuid, uploaded_at timestamptz,
  verified_by       uuid, verified_at timestamptz,
  rejection_reason  text,
  retention_expires_at date,
  deleted_at        timestamptz,
  UNIQUE (student_id, pack, slot_key)
);
```

Note the added `rejected` status — a blurry passport can now be sent back with a reason instead of sitting as `uploaded` forever. **Direct-to-R2 presigned upload** is a v2 optimisation and stays scan-gated (browser → API → magic-byte → ClamAV → readable). Start with browser→API so nothing is readable before scan.

---

## 6. OWASP ASVS L2 checklist → actionable tasks, mapped to phases

| ASVS | Requirement | Task | Where | Phase |
|------|-------------|------|-------|:---:|
| 1.2.1 | Unique low-priv service accounts | DB app-role without `DROP`/`SUPERUSER`; per-bucket R2 tokens | infra | P0 |
| 1.4.4 | Enforce access control server-side | Policies + RLS; **CI test** fails on untenanted partner query | `tests/Tenancy` | P1/P6 |
| 2.1.7 | Breached-password check | HIBP k-anonymity on register/reset | `PasswordRule` | P4 |
| 2.2.1 | Anti-automation on auth | Redis rate limits + Turnstile (§3.2) | middleware | P4 |
| 2.5.4 | No predictable recovery secrets | OTP bound to `flow_id`, hashed, single-use | `VerificationService` | P4/P6 |
| 2.7.x | OTP lifecycle | 6-digit CSPRNG, 10-min TTL, 5 attempts, 3/hr send | `email_verification_codes` | P4 |
| 2.8.1 | MFA for admin | Mandatory TOTP on all staff roles | Filament MFA | P1 |
| 3.2.1 | New session token on auth | Rotate session id on login/TOTP/pw-change | Sanctum | P1/P4 |
| 3.3.1 | Logout invalidates server-side | Redis session delete + cookie clear | `LogoutController` | P4/P6 |
| 3.4.x | Cookie attributes | `HttpOnly; Secure; SameSite=Lax` all scopes | `config/session.php` | P1 |
| 4.1.3 | Least privilege / default deny | Gate every route; no-policy = 403 | policies | P1+ |
| 4.2.1 | No IDOR | Self endpoints (`/api/me/*`); no id in path | routes | P5 |
| 4.2.2 | Anti-CSRF | Double-submit XSRF + `Sec-Fetch-Site` | `js/api.js` | P1 |
| 5.1.x | Server-side input validation | Re-implement all client FILTERS as FormRequests | FormRequests | P2+ |
| 5.3.3 | Output encoding / URL scheme allow-list | `http/https/mailto/relative` validator on URL fields | `SafeUrlRule` | P2/P3 |
| 6.2.x | Cryptography | argon2id; TLS 1.2+; no home-rolled crypto | config | P0/P1 |
| 6.4.1 | Secret management | Secrets manager, not `.env` in repo (§7) | infra | P0 |
| 7.1.1 | Log security events, no sensitive data | `auth_events`, `audit_log`, `document_access_log` + scrubber | §8 | P1+ |
| 8.1.1 | No sensitive data in logs/URLs | `flow_id`, scrubber, `no-store` | §8 | P4 |
| 8.2.2 | No sensitive data client-side | Stop `localStorage` profile persistence | `js/store.js` | P5 |
| 8.3.4 | Encrypt sensitive data at rest | R2 SSE + optional envelope encryption | §5 | P5 |
| 9.1.1 | TLS everywhere + HSTS preload | Cloudflare + nginx | infra | P0 |
| 11.1.x | Business-logic limits | Wallet idempotency, tenant scope, quota atomicity | services | P7/P9 |
| 12.1.1 | Upload size/type limits | Magic-byte + size cap | upload | P5 |
| 12.3.x | No path traversal / server-side path build | UUID keys | §5 | P5 |
| 12.4 | Malware scan | ClamAV scan-gate | queue | P5 |
| 13.2.x | REST auth on every endpoint | Sanctum guards on all `/api` | routes | P1+ |
| 14.1.x | Deploy hardening | Migrations one-shot job; no debug in prod | CI/CD | P0 |
| 14.4.1 | Security headers | CSP, `X-Content-Type-Options`, `Referrer-Policy` | middleware | P0/P2 |

**Security response headers (nginx / Laravel middleware):**

```text
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
Content-Security-Policy: default-src 'self'; img-src 'self' https://<cdn>; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Cache-Control: no-store   # on all authenticated responses
```

`[ASSUMPTION]` The inline `<script>window.VFI_BOOTSTRAP=…</script>` content bundle needs a per-response CSP nonce, or move the bundle to an external `self` script fetched before `store.js`.

---

## 7. Secrets management & key rotation

| Secret | Store | Rotation | Notes |
|--------|-------|----------|-------|
| DB credentials | Managed-PG rotation + secrets manager `[ASSUMPTION: AWS SSM / Vault]` | 90 days | app-role only, never superuser |
| `APP_KEY` (Laravel encryption) | Secrets manager | on suspected compromise | rotating requires re-encrypting `encrypted` cast columns — dual-key window (`key:rotate` pattern) |
| Argon2 pepper (if used) | Secrets manager | 180 days, dual-key | old pepper kept until all hashes migrate on next login |
| OTP/flow pepper | Secrets manager | 90 days | short-lived codes make rotation low-risk |
| R2 tokens (pub/priv separate) | Secrets manager | 90 days | scoped per bucket, least privilege |
| Postmark/SES keys | Secrets manager | 90 days | — |
| PSP + webhook signing secrets | Secrets manager | per provider policy | dual-secret window to accept in-flight webhooks |
| TOTP shared secrets | DB `encrypted` cast, key in secrets manager | on user re-enrol | never logged, never in backup export |
| Session/CSRF keys | derived from `APP_KEY` | with `APP_KEY` | — |

Rules: no secret in the git repo, in a committed `.env`, in the Filament UI, in logs, or in the backup export. CI (`gitleaks`) blocks secret commits. On-box `.env` is `chmod 600`, owned by the deploy user. Every rotation writes an `audit_log` entry; the rotation runbook lives in the ops repo.

---

## 8. Audit logging — who-did-what-to-whose-data, without PII

### 8.1 Three append-only stores (no `UPDATE`/`DELETE` grant to the app role)

```sql
CREATE TABLE auth_events (              -- identity actions
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  event_type text NOT NULL,            -- signin_success|signin_fail|otp_sent|otp_failed|reset_*|role_*|...
  actor_user_id uuid,                  -- nullable (anon attempt)
  subject_user_id uuid, subject_agency_id uuid,
  email_hash bytea,                    -- sha256(lower(email)); NOT the address, for failed-signin correlation
  result text, failure_reason text,
  ip inet, user_agent text, request_id uuid,
  metadata jsonb,                      -- scrubbed
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE audit_log (               -- content + money + role changes
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor_type text, actor_id uuid, agency_id uuid,
  action text NOT NULL, entity_type text, entity_id text,
  before_json jsonb, after_json jsonb, -- scrubbed of document bytes / secrets / PII values
  ip inet, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE document_access_log (     -- every read of a sensitive file
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  document_id uuid NOT NULL,
  actor_user_id uuid, actor_role text, acting_partner_agency_id uuid,
  action text CHECK (action IN ('view','download','signed_url_issued','delete')),
  ip inet, user_agent text, created_at timestamptz NOT NULL DEFAULT now()
);
```

### 8.2 What to log vs never log

| Log this | NEVER log this |
|----------|----------------|
| `event_type`, actor id, subject id, agency id | passwords, password hashes |
| `sha256(email)` for correlation | raw email addresses (hash; mask in UI) |
| document id, slot, action, sha256 | document bytes, file contents, OCR text |
| `request_id`, ip, coarse UA | OTP plaintext, session tokens, CSRF tokens |
| before/after **field names + non-PII values** | PII field values (redact `passport_no`, `dob`, `bank_*` → `"[redacted]"`) |
| PSP `provider_event_id`, amount, status | card PAN / CVV (never touches us — §9.3) |

**Scrubber.** A Monolog processor with a key deny-list (`password`, `code`, `token`, `dob`, `passport`, `bank`, `authorization`, `cookie`) replaces values with `[redacted]` before any handler writes — applied to app logs **and** to `metadata`/`before_json`/`after_json` before insert.

Retention: security logs ≥ 12 months. Alerting (Sentry + log-based): failed-login bursts, OTP send floods, admin export, page toggle, wallet refund/adjust, any `av_scan_status='infected'`, and Postgres RLS `insufficient_privilege` errors (a code bug bypassing the scope).

---

## 9. Compliance

### 9.1 Bangladesh context

- `[ASSUMPTION]` No comprehensive Bangladesh data-protection statute is in force at build time; the **draft Personal Data Protection Act** and sectoral rules (BTRC, Cyber Security Act) may impose **data-localisation** and breach-notification duties. Design so residency is a **config choice**: managed Postgres + R2 region are selectable (Singapore/Mumbai chosen for Dhaka latency; movable to an in-country node if localisation lands).
- Data-processing agreements with every processor: managed-PG provider, R2, Postmark/SES, ClamAV host, PSPs, Flywire, Élan lender.
- Consent captured at collection (`terms_acceptances`, versioned, with IP/UA).

### 9.2 GDPR exposure (via EU/UK universities & applicants)

UK/EU GDPR obligations attach the moment a student's documents are shared onward with UK/EU institutions.

| GDPR duty | Implementation |
|-----------|----------------|
| Lawful basis + consent record | `terms_acceptances` (document, version, timestamp, IP); **separate consent for onward transfer** to each university/lender |
| Right of access (SAR) | `GET /api/me/export` (student) + staff-initiated export job producing a structured bundle (profile, documents list, applications, access log) |
| Right to erasure | soft-delete + scheduled hard-delete job; **legal-hold exception** for submitted applications and financial records under retention |
| Record of onward disclosure | `document_disclosures` table (below) — the record a regulator actually asks for. Do not skip. |
| Retention clock | per-document `retention_expires_at`; per-category defaults (§9.4) |
| Data minimisation | store enums not free text where possible; hash emails in logs |
| Breach notification (72 h) | IR runbook §10 triggers regulator + subject notice path |
| International transfer | SCCs / UK IDTA with EU/UK institutions receiving documents `[ASSUMPTION: legal to draft]` |

```sql
CREATE TABLE document_disclosures (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  document_id uuid NOT NULL, student_id uuid NOT NULL,
  recipient text NOT NULL,             -- university / lender name
  recipient_country text,
  legal_basis text,
  disclosed_by uuid, disclosed_at timestamptz NOT NULL DEFAULT now()
);
```

### 9.3 PCI-DSS — stay out of scope by design

The wallet moves money but **must never touch a card PAN**.

| Rule | How |
|------|-----|
| No card data in our system | top-ups go through PSP-hosted checkout / redirect (bKash, Nagad, SSLCommerz, or PSP iframe); we store only `provider_intent_id` + status |
| SAQ level | target **SAQ-A** (all cardholder data handled by a PCI-validated third party; we only redirect/embed hosted fields) |
| Wallet credit | only from a **verified signed webhook**, deduped on `provider_event_id`, inside a DB transaction — never from the browser callback |
| Ledger | append-only, NUMERIC, `balance_after`, idempotency key on top-up and fee debit |
| Flywire tuition | Flywire is merchant of record; we hold only `flywire_payment_id` + status; requires a real commercial agreement before launch |
| No card fields anywhere | front end never renders a PAN input; CSP `form-action 'self'` + hosted-field origin |

If the team ever accepts cards directly, PCI scope explodes to SAQ-D — do not; keep the hosted-checkout boundary.

### 9.4 Retention & deletion schedule `[ASSUMPTION — confirm with client/legal]`

| Data class | Retention | Deletion job |
|------------|-----------|--------------|
| Visa/financial documents | 12 months after departure or dormancy, then hard-delete (legal-hold overrides) | daily scheduler, R2 object + row |
| Application records | engagement + 3 years (audit/dispute) | archive then purge |
| Wallet ledger | 7 years (financial record) | never auto-purge; archive |
| Security logs | 12 months | rolling |
| OTP codes | consumed/expired → purge at 24 h | hourly |
| Contact enquiries | 24 months, then purge | monthly |
| Marketing images | until unreferenced (ref-count sweep) | orphan sweep |

---

## 10. Incident-response runbook

**Roles:** IC (Incident Commander — lead dev), Comms (client liaison), Scribe (timeline). Contact tree + out-of-band channel `[ASSUMPTION: Signal group]` documented off-platform.

### 10.1 Severity ladder

| Sev | Example | Target response |
|-----|---------|-----------------|
| SEV-1 | document bucket exposure, confirmed tenant cross-read, admin account compromise, wallet ledger tampering | immediate, 24×7 |
| SEV-2 | credential-stuffing success on ≥1 account, OTP/mail amplification abuse, single infected upload reaching staff | same business day |
| SEV-3 | failed-login burst blocked by controls, single stray log with PII | next business day |

### 10.2 Phases

1. **Detect** — Sentry alert / log threshold / user report / provider notice. Scribe opens the timeline.
2. **Triage** — classify severity; identify the boundary (§1) and the affected data classes.
3. **Contain** —
   - compromised session: `sessions` revoke family, "sign out everywhere";
   - compromised admin: disable user, rotate `APP_KEY`/pepper, force TOTP re-enrol;
   - leaked secret: rotate that secret (§7), invalidate dependent tokens;
   - malware upload: quarantine object, block agency/user, scan siblings;
   - tenant leak: kill-switch the offending endpoint, verify RLS, patch the scope.
4. **Eradicate** — patch root cause; add a regression test (tenant CI test, new rate limit).
5. **Recover** — restore from **PITR** if integrity affected; pre-restore snapshot before any import; verify ledger balances reconcile.
6. **Notify** — if personal data breached: GDPR 72-hour clock to the relevant supervisory authority; notify affected subjects; Bangladesh regulator per then-current law. Comms uses pre-drafted templates.
7. **Post-incident** — blameless review within 5 business days; action items tracked; update this runbook.

### 10.3 Forensic preservation

- `auth_events`, `audit_log`, `document_access_log` are append-only → primary evidence.
- Snapshot the managed-PG PITR point + Redis + R2 access logs at detection time, before remediation.
- Never delete during an open incident; place affected records under legal hold.

### 10.4 Pre-wired detections (build as alerts)

`signin_fail` spike per IP/ASN · `otp_sent` flood per email · any `admin_export` / `backup_import` / `page_toggled` · wallet refund/adjust · `av_scan_status='infected'` · Postgres RLS `insufficient_privilege` errors · webhook signature-verification failures.

---

## Appendix A — Tenancy enforcement (the #1 confidentiality control)

```php
// app/Models/Concerns/BelongsToAgency.php
trait BelongsToAgency {
  protected static function bootBelongsToAgency(): void {
    static::addGlobalScope('agency', function ($q) {
      if ($id = app(TenantContext::class)->agencyId()) {   // from SESSION only
        $q->where($q->getModel()->getTable().'.partner_agency_id', $id);
      } else { $q->whereRaw('1 = 0'); }                     // default-deny
    });
    static::creating(fn($m) => $m->partner_agency_id ??= app(TenantContext::class)->agencyId());
  }
}
```

```sql
-- Independent second net: Postgres RLS
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON students
  USING (partner_agency_id = current_setting('app.agency_id')::uuid);
-- middleware runs:  SET LOCAL app.agency_id = '<session agency>';
```

```php
// tests/Feature/TenancyTest.php  — CI fails the build if any partner table is queried untenanted
it('never queries a partner table without a tenant predicate', function () {
    DB::listen(function ($q) {
        foreach (self::PARTNER_TABLES as $t) {
            if (str_contains($q->sql, $t)) {
                expect($q->sql)->toMatch('/partner_agency_id\s*=/i');
            }
        }
    });
    // exercise every partner controller...
});
```

## Appendix B — `js/api.js` (ES5, CSRF + 401 redirect, one file for all pages)

```javascript
// var/function/string-concat only — no build step
window.VFI_API = (function () {
  function xsrf() {
    var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function req(method, path, body) {
    return fetch('/api' + path, {
      method: method, credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
      body: body ? JSON.stringify(body) : null
    }).then(function (r) {
      if (r.status === 401) { window.location.href = r.headers.get('X-Login-Url') || 'login.html'; return; }
      return r.json();
    });
  }
  return { get: function (p) { return req('GET', p); },
           post: function (p, b) { return req('POST', p, b); } };
})();
```

---

## Assumptions register — confirm before build

1. Office/VPN IP allow-list available for admin `/api/admin` and Filament (else TOTP + WAF is the floor).
2. Secrets manager = AWS SSM Parameter Store or Vault (region-appropriate).
3. Upload caps: image 10 MB, PDF 20 MB, per-student total 200 MB.
4. No Bangladesh data-localisation mandate at launch; residency is config-switchable if one lands.
5. Retention windows in §9.4 are drafts pending client/legal sign-off (esp. document retention after departure and legal-hold scope).
6. Optional argon2 app-level pepper and customer-managed envelope encryption for documents — decide on client risk appetite (both add rotation cost; recommend the pepper if the ops cost is acceptable).
7. No native/mobile client (keeps Sanctum cookie mode; revisit with PATs if one appears).
8. PSP(s) offer hosted checkout/redirect so we stay at PCI SAQ-A; confirm bKash/Nagad vs SSLCommerz/card flows.
9. Legal capacity to draft SCCs/UK IDTA and per-university onward-transfer consent before the first real document is shared.
