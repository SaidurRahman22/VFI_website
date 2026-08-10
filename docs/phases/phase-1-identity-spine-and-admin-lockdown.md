# Phase 1 — Identity Spine & Admin Lockdown (P0)

**What this is:** the build sheet for the single users/roles/sessions foundation and the closure of the product's highest-severity gap — `admin.html` has no authentication — before any server-backed admin endpoint can exist. **Who it's for:** the backend/API dev, the Filament/admin dev, and whoever signs the hard gate.

See [phases/README.md](README.md) for the gate model, [memory.md](../../memory.md) for the admin panel it locks down, and [BACKEND_DEVELOPMENT_PLAN.md](../BACKEND_DEVELOPMENT_PLAN.md) for the security-architecture detail.

| | |
|---|---|
| **Goal** | Build users/roles/sessions once; ship the Filament admin behind mandatory login + TOTP; land the tenancy machinery and its CI guard. |
| **Duration** | 3–4 weeks |
| **Depends on** | [P0](phase-0-platform-and-delivery-foundation.md) complete (platform, CI/CD, same-origin, empty DB). |
| **Blocks** | **P3** (no admin write endpoint before admin auth), and every phase that needs identity. This is a **hard gate** — the admin-auth criterion cannot be waived or carried as debt. |

> **Why this is P0.** `admin.html` loads and renders a fully functional editor with no login, advertising the fact in its own UI. It exposes full content CRUD, a whole-site JSON import, a "reset to demo" button, and a page kill-switch. A server-backed admin with no auth is strictly *worse* than the localStorage demo — it turns a local-only tool into a public write endpoint. Closing this is the single highest-priority item in the product.

---

## Prerequisites

| Need | Detail |
|---|---|
| P0 exit gate green | Same-origin proven, signed-image deploy, managed DB reachable, Sanctum + Pennant installed |
| `js/api.js` present | The CSRF/401 helper from P0 — this phase proves it against a real authed endpoint |
| Open question | Office/VPN **IP allow-list** for `/api/admin` — availability unknown. Design must **not depend** on it; TOTP + WAF is the floor. |

---

## In scope

1. `users` (argon2id), `user_roles` (tenant-bound roles carry NOT-NULL `agency_id` via CHECK), `sessions`, `auth_events` (append-only) — tables, migrations, native PG enums mirrored to PHP 8.3 enums.
2. Sanctum cookie sessions, **three scopes** (student/partner/admin); admin scope on `/api/admin` prefix, SameSite=Strict, 15-min idle + absolute expiry.
3. `admin-login.html` (new) + `admin.html` gated: renders nothing until authenticated, redirects on 401; **mandatory TOTP** enrolment + verification for every admin/staff role.
4. Filament v4 panel installed behind that auth — dashboard shell only, **no content CRUD**.
5. RLS scaffold: `BelongsToAgency` global-scope trait + Postgres RLS FORCE policies against `SET LOCAL app.agency_id`. No partner tables yet, but the machinery + the CI untenanted-query test **land now**, exercised by a synthetic partner-table fixture.
6. Invite-only admin account creation (`admin_invites`); superadmin bootstrap via a sealed Artisan command; self-demotion protection (never remove the last superadmin).
7. Double-submit CSRF live end-to-end via `js/api.js` against a first authed test endpoint; Sec-Fetch-Site check.
8. Server-side rate-limiting framework (Redis) for auth endpoints; argon2id dummy-hash constant-timing helper.

## Out of scope

- Any content/CMS CRUD ([P3](README.md)), student or partner auth flows (P4/P6), any partner tenant data.
- Password-reset landing pages — built with the actor that needs them (P4 student, P6 partner).
- MFA for partner/student actors — admin/staff only here.

---

## Role model

Native PG enum mirrored to a PHP 8.3 enum. Tenant-bound roles carry a NOT-NULL `agency_id`; the rest carry NULL, enforced by a CHECK.

| Role | Tenant-bound? | Notes |
|---|---|---|
| `student` | no | Created P4 |
| `partner_owner` | **yes** | Created P6 on approval |
| `partner_counsellor` | **yes** | Seat, created P6 |
| `staff_counsellor` | no | VFI staff |
| `staff_partner_ops` | no | Reviews agency applications (P6) |
| `staff_finance` | no | Money writes (P9) |
| `content_editor` | no | CMS only (P3) |
| `superadmin` | no | Owner: backup import, reset, page toggles, admin users |

Only the machinery + the enum land now; the tenant-bound roles have no data until P6.

---

## Work breakdown

### 1. Identity schema
1.1 `users` — argon2id hash (64 MiB / t=3 / threads=1), `email` citext unique, `status` enum, `failed_login_count`, `locked_until`, `mfa_enrolled_at`, timestamps + `deleted_at`.
1.2 `user_roles` — `role` enum, `agency_id` (NOT-NULL for `partner_*` via CHECK), `granted_by`, `granted_at`, `revoked_at`.
1.3 `sessions` — Sanctum-backed; `active_role`, `active_partner_agency_id` (null now), idle + absolute expiry, `revoked_reason`, ip, user_agent.
1.4 `auth_events` — append-only. **REVOKE UPDATE/DELETE from the app role** at migration time.

### 2. Sanctum cookie sessions, three scopes
2.1 Admin scope on `/api/admin/*`, SameSite=Strict, 15-min idle + absolute cap.
2.2 Prove the three cookie scopes on one origin do **not** collide — correct path/domain attributes so a student cookie never authenticates an admin route.
2.3 `Cache-Control: no-store` on every authed response.

### 3. Admin lockdown
3.1 New `admin-login.html` posting to `POST /api/admin/login`.
3.2 `admin.html` boot gated: a session check runs first; on 401 it redirects and **renders nothing** (no editor DOM, no `VFI` data).
3.3 Mandatory TOTP: enrolment (QR + shared secret) then verification. A valid password without TOTP cannot reach the panel.

### 4. Filament panel (shell only)
4.1 Install Filament v4 behind the admin auth. Dashboard shell, no resources.
4.2 Confirm no Filament route is reachable unauthenticated.

### 5. Tenancy machinery + CI guard (lands now, guards all future phases)
5.1 `BelongsToAgency` Eloquent global scope — `agency_id` from session **only**, never a request param.
5.2 Postgres RLS FORCE policies keyed on `SET LOCAL app.agency_id`, as an independent second net.
5.3 A **synthetic partner-table fixture** to exercise both nets before real partner tables exist.
5.4 **CI tenancy-guard test** that fails if any partner-scoped query lacks an `agency_id` predicate — this is a BLOCK-class gate for every future phase.

```php
// The second net: stripping the Eloquent scope must return ZERO rows, not another tenant's.
DB::statement("SET LOCAL app.agency_id = '00000000-0000-0000-0000-000000000001'");
$rows = SyntheticPartnerRow::withoutGlobalScope(BelongsToAgency::class)->get();
assert($rows->isEmpty()); // RLS FORCE denies the read even with the app scope removed
```

### 6. Admin account lifecycle
6.1 `admin_invites` — invite-only, expiring, single-use, superadmin-issued. **No admin sign-up UI, ever.**
6.2 Sealed Artisan command to bootstrap the first superadmin.
6.3 Self-demotion protection: the last superadmin cannot demote or delete itself.

### 7. CSRF end-to-end
7.1 Double-submit token via `js/api.js` against a first authed test endpoint.
7.2 Sec-Fetch-Site check as a second signal. A cross-site state-changing request without the token is rejected.

### 8. Rate limiting + timing
8.1 Redis per-account + per-IP lockout with progressive delay; 15-min lock after ~10 consecutive failures.
8.2 argon2id **dummy-hash** helper so unknown-account and wrong-password take the same time.
8.3 Monolog PII/secret scrubber deny-list on all auth logging.

---

## Deliverables

- `admin-login.html` + TOTP enrolment flow; `admin.html` serving nothing pre-auth.
- `users` / `user_roles` / `sessions` / `auth_events` schema live; the 8-role model.
- Filament panel reachable **only** after admin login + TOTP.
- Tenancy machinery (global scope + RLS + CI guard test) present and exercised by the synthetic fixture.
- `js/api.js` CSRF + 401-redirect proven against a real authed endpoint.

---

## Security work

| Item | Detail |
|---|---|
| **THE P0** | Admin panel requires login + **mandatory TOTP**; no admin route reachable unauthenticated |
| Password storage | argon2id (64 MiB / t=3 / threads=1) + dummy-hash enumeration safety |
| Lockout | per-account + per-IP progressive delay via Redis (server-side, not client cooldown) |
| Audit | `auth_events` append-only (REVOKE UPDATE/DELETE from app role); Monolog PII/secret scrubber |
| Tenancy | CI untenanted-query test lands now and guards all future phases |
| Admin surface hardening | optional office/VPN IP allow-list for `/api/admin` (if available); TOTP + WAF as the floor |

---

## Testing

| Test | Passes when |
|---|---|
| Unauthenticated admin | Loading `admin.html` or any `/api/admin` route without a session returns 401/redirect and renders **no data** |
| TOTP enforced | Valid password without TOTP cannot reach the panel; replayed/expired TOTP rejected |
| Lockout | Lockout + rate-limit tests hit **real Redis counters**, not client cooldowns |
| Tenancy (second net) | Stripping the Eloquent scope on the synthetic partner table returns zero rows via RLS |
| CSRF | A cross-site state-changing request without the double-submit token is rejected |
| Self-demotion | The last superadmin cannot demote/delete itself |

---

## Exit gate

- [ ] No admin endpoint or `admin.html` content is reachable without an authenticated admin session — verified by an **automated negative test in CI**. *(hard gate)*
- [ ] TOTP is mandatory for admin sign-in and cannot be bypassed; a demo shows enrolment → login → panel.
- [ ] The CI untenanted-query tenancy test is present and green, and RLS returns zero rows when the app scope is removed.
- [ ] Sanctum cookie session + double-submit CSRF works end-to-end from a static page via `js/api.js`.
- [ ] Admin account creation is invite-only; the last superadmin cannot demote/delete itself.

**The admin-auth criterion is a hard gate** — it cannot be waived or carried forward. Two-party sign-off per [README](README.md#gate-model).

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Admin auth is load-bearing — any slip blocks P3 entirely | Treat the first exit-gate box as non-negotiable; do not start P3 until it is green |
| Three cookie scopes on one origin can collide | Prove isolation now (step 2.2) with an explicit cross-scope negative test |
| IP allow-list availability unknown | Design does not depend on it; TOTP + WAF is the floor, allow-list is additive |
| Tenancy machinery built before real partner tables feels abstract | The synthetic fixture makes it testable now, so P6 inherits a proven net rather than building one under deadline |

---

## Frontend files / REAL REQUEST markers wired

No public-actor `REAL REQUEST` markers this phase — the `js/auth.js` / `js/partner-auth.js` markers remain stubbed.

| File | Change |
|---|---|
| `admin-login.html` | New. Wired to a real `POST /api/admin/login` + TOTP. |
| `admin.html` | Boot gated on a session check; renders nothing pre-auth. |
| `js/api.js` | Proven against the first real authed endpoint (CSRF + 401 redirect). |

No demo disclaimer removed (those belong to the public auth pages, wired P4/P6).

**Previous:** [Phase 0 — Platform & Delivery Foundation](phase-0-platform-and-delivery-foundation.md) · **Next:** [Phase 2 — Public Content Read Path](phase-2-public-content-read-path-storejs-seam-and-contact-form-persistence.md).
