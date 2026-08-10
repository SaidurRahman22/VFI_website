# Phase 6 (P6) — Partner Identity & Tenancy

**What this is:** the build spec for VFI's partner (agency) identity layer and the tenant-isolation net that every later partner feature depends on. **Audience:** the backend/API dev, the Filament dev, and the frontend-seam dev wiring `js/partner-auth.js`.

> Create the tenant, prove it cannot leak, then — and only then — let Phase 7/8/9 build data surfaces on top of it. The tenancy-isolation criterion in the [Exit gate](#exit-gate) is a **hard gate**: it cannot be waived or carried as debt.

Related docs: [Phase 4 — Student Identity](phase-4-student-identity.md) · [Phase 5 — Student Portal](phase-5-student-portal.md) · [Phase 7 — Partner Console Core](phase-7-partner-console-core.md) · [Phase 9 — Money & Launch](phase-9-money-surface-staff-backoffice-and-launch.md) · [Backend master plan](../BACKEND_DEVELOPMENT_PLAN.md) · [agent orientation](../../memory.md)

---

## Goal

Build agency registration (the 3-step wizard as a **reviewable application**, not an instant account), the staff review workflow that mints the live tenant, partner sign-in bound to one agency, hardened partner OTP/reset/email-change — and **prove tenant isolation** (Eloquent global scope + Postgres RLS + a CI untenanted-query test) against the first real partner tables before any partner data surface exists.

## Duration

4–5 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Identity spine (`users`, `user_roles`, `sessions`, `auth_events`), Sanctum cookie scopes, Redis rate-limit framework, argon2id + dummy-hash helper | [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | Partner users are rows in the same `users` table; partner cookie scope already defined |
| RLS scaffold: `BelongsToAgency` trait + `SET LOCAL app.agency_id` policy machinery + the CI untenanted-query test | [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | Machinery lands in P1 against a synthetic table; P6 points it at real partner tables |
| Opaque `flow_id` OTP surface (`email_verification_codes`, `/auth/otp/{verify,resend}`), Postmark sending, reset-token model + `student-reset.html` pattern | [Phase 4](phase-4-student-identity.md) | Partner OTP/reset reuse the same hardened primitives; only the wizard payload and email-change binding are new |
| Filament admin panel behind login + TOTP | [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | The staff review workflow is a Filament resource |
| `js/api.js` CSRF + 401-redirect helper | [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | Partner auth pages POST through it |

**Product decision that must be settled before Phase 7 writes attribution (raise now):** the student-attribution collision rule (self-signup vs partner-registered vs QR self-registration of the same person). It is a commercial/commission question. P6 does not need it resolved to ship, but P7 does — flag it at P6 sign-off.

---

## In scope

- `partner_agencies` (THE tenant), `partner_agency_members` (seats), `partner_applications` (held for review), `agency_sessions`.
- 3-step registration wizard assembled from **all three** DOM subtrees, creating a `pending_verification` user + a `partner_application` (never a live tenant).
- Partner email OTP + a **redesigned email-change** flow bound to a server-side pending-registration record keyed by `flow_id`.
- Partner password reset + a new `vfi-partner-reset.html` landing page.
- Partner sign-in that resolves the tenant from the session only and refuses non-active agencies.
- Staff review workflow (Filament): approve / reject / more-info → approval mints the tenant and grants the first `partner_owner` role; suspend/close.
- RLS + `BelongsToAgency` turned on for the real partner tables; `partnerName` greeting resolved from the session.

## Out of scope (explicit)

| Not here | Where |
|---|---|
| Partner console data pages (students, applications, wallet, search, enquiries) | [Phase 7](phase-7-partner-console-core.md) / [Phase 8](phase-8-program-search.md) / [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) |
| QR self-registration landing page + `agency_referral_links` attribution | [Phase 7](phase-7-partner-console-core.md) |
| Commission ledger surface | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) — schema-ready, surface deferred, build-or-remove is a client call |
| Multi-seat invite UI (counsellor invitations) | Deferred — the **table** is built now; the invite screen is later product surface |
| Wallet provisioning beyond the FK stub | [Phase 9](phase-9-money-surface-staff-backoffice-and-launch.md) |

---

## Work breakdown

### 1. Schema & migrations

Expand/contract discipline applies: every migration must be safe against N-1 code.

| Table | Key columns | Notes |
|---|---|---|
| `partner_agencies` | `id`, `legal_name`, `country`, `city`, `status` (enum `pending_review\|approved\|rejected\|suspended\|closed`), `tier_id?`, `seat_limit` (default 2), `wallet_id?`, `approved_by_user_id?`, `approved_at?`, `rejected_reason?`, `created_at` | THE tenant. `status` is a native PG enum mirrored to a PHP 8.3 enum |
| `partner_agency_members` | `id`, `partner_agency_id`, `user_id`, `seat_role` (enum `owner\|counsellor\|finance_viewer`), `contact_person_name`, `work_email`, `phone_cc`, `phone_national`, `invited_by_user_id?`, `accepted_at?`, `status` (enum `invited\|active\|disabled`) | Model seats **now** even though the wizard creates exactly one. Retrofitting seats onto a shared login is painful |
| `partner_applications` | `id`, `agency_name`, `country`, `city`, `contact_person`, `work_email`, `phone_cc`, `phone_national`, `user_id` (created immediately, `pending_verification`), `terms_accepted_version`, `authorised_signatory_attested` (bool), `submitted_at`, `submitted_ip`, `review_status` (enum `pending\|approved\|rejected\|more_info`), `reviewed_by_user_id?`, `reviewed_at?`, `review_notes?` | Separate from `partner_agencies` so a rejected/duplicate application never pollutes the live tenant table |
| `agency_sessions` | modelled via the Phase 1 `sessions` table with `active_partner_agency_id` + `active_role` bound | No new table if the P1 `sessions` shape already carries the tenant binding; otherwise add the columns |

- Add `partner_agency_id` FK to `partner_agency_members` and (dormant) to the tables Phase 7+ will fill; keep it **denormalised** onto every future partner-scoped row so scoping never needs a join.
- `password_hash` on the wizard user is written argon2id on arrival — never stored raw, never held in a queue.

### 2. Tenancy net (the hard part — do this before any read surface)

```php
// Every partner-scoped model uses this trait.
trait BelongsToAgency {
    protected static function bootBelongsToAgency(): void {
        static::addGlobalScope('agency', function ($q) {
            $agencyId = app(TenantContext::class)->agencyId(); // from SESSION only
            if ($agencyId === null) { $q->whereRaw('1 = 0'); return; } // default-deny
            $q->where($q->getModel()->getTable().'.partner_agency_id', $agencyId);
        });
    }
}
```

- `TenantContext` is populated by middleware from the authenticated partner session. **Never** from a request param, query string, or body. There is no `?agencyId=` anywhere.
- Postgres RLS as an independent second net, set per request inside the transaction:

```sql
ALTER TABLE partner_agency_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE partner_agency_members FORCE ROW LEVEL SECURITY;
CREATE POLICY agency_isolation ON partner_agency_members
  USING (partner_agency_id = current_setting('app.agency_id', true)::uuid);
-- middleware runs: SET LOCAL app.agency_id = '<uuid-from-session>';
```

- A forgotten `WHERE` clause returns **zero rows** (RLS), not a competitor's data.
- Wire the CI untenanted-query test (from Phase 1) to the real partner tables: it must fail the build if any partner-scoped table is queried without a tenant predicate.

### 3. Registration wizard (server side)

The frontend sample body is wrong — `js/partner-auth.js:769` calls a `collect(form)` that does not exist, and the steps live in three separate `[data-pa-step]` DOM subtrees. Derive the real payload from the markup:

| Step | Fields (DOM ids) | Server action |
|---|---|---|
| 1 agency | `#paRgAgency`, `#paRgCountry` (13-value list), `#paRgCity` | Validate against the country allow-list |
| 2 person | `#paRgPerson`, `#paRgEmail`, `#paRgDial` + `#paRgPhone` (composed by `fullPhone()`) | Re-validate; steps 1–2 are client-only today and trivially bypassed |
| 3 password | `#paRgPass`, `#paRgPass2` (match), `#paRgAgree` (attests BOTH terms AND authority to bind the agency) | Hash password argon2id; store `terms_accepted_version` + `authorised_signatory_attested`; **do not** create a live tenant |

- On submit: create `users` row (`pending_verification`) + `partner_applications` row, issue an OTP `flow_id`, return the opaque `flow_id`. Enumeration-safe: the response must not reveal whether the email/agency already exists.
- Duplicate-agency check (same `legal_name` + `country`) is a soft signal to staff review, not a client-visible error.

### 4. Partner OTP + hardened email-change

- OTP verify/resend reuse the Phase 4 `flow_id` surface (`email_verification_codes`): CSPRNG 6 digits, hashed at rest, 10-min TTL, max 5 attempts then destroy, single-use, new send invalidates prior. Kills "any six digits pass" (`js/partner-auth.js:1267`).
- **Email-change (`js/partner-auth.js:1349–1380`, REAL REQUEST 1364) is an account-takeover vector as written** — it takes the address from the `?email=` URL and re-points the code to any typed address, unauthenticated. Correct model:
  - The code is bound to the server-side pending-registration record identified by `flow_id`.
  - Changing the destination requires possession of that `flow_id`.
  - The change restarts the flow, invalidates every prior code, and is rate-limited (max 2 address changes per registration).
- Remove the `?email=` URL pattern and the `history.replaceState` write-back (`js/partner-auth.js:1098`); keep `maskEmail` for display only.

### 5. Partner password reset

- Reuse `password_reset_tokens` (32 CSPRNG bytes, hashed, 30–60 min, single-use, supersede-on-new).
- Build `vfi-partner-reset.html` (sibling of `vfi-partner-forgot.html`) — it does not exist in the repo today.
- A successful reset **revokes every session across all agencies** the user belongs to.

### 6. Partner sign-in + tenant binding

- `POST /api/partner/signin` (`js/partner-auth.js:761`): authenticate, resolve `partner_agency_id` + `seat_role`, bind them to the session.
- **Refuse** sign-in when the agency `status` is `pending_review`, `rejected`, or `suspended` (the review gate at `js/partner-auth.js:93` is real product copy — enforce it).
- Real logout: `js/portal.js logout()` (line 313) currently only clears the `pp_collapsed` localStorage key — wire it to revoke the server session.
- Move `partnerName` off the global admin string (`partnerPortal().partnerName`, `js/portal-render.js:42`) — resolve the greeting and avatar initial from the authenticated member.

### 7. Staff review workflow (Filament)

- Filament resource over `partner_applications`: list, view, and actions **Approve / Reject / Request more info**.
- Approve → inside one transaction: create exactly one `partner_agencies` row, create the owning `partner_agency_members` row with `seat_role = owner`, grant the `partner_owner` role, set `review_status = approved`, write an `auth_event` (`agency_approved`) + audit row, email the applicant.
- Reject → set status, record `rejected_reason`, email; **do not** create a tenant.
- Suspend/close an agency → block sign-in, revoke every member session, freeze future writes (status flips consumed by the sign-in gate and Phase 9 wallet writes).

---

## Deliverables

- Working agency registration → OTP → `pending_review`, and a Filament review screen that approves into a live tenant with an owner seat.
- Partner sign-in bound to one agency with a seat role; suspended/pending agencies blocked.
- `vfi-partner-reset.html` completing the partner reset loop; hardened email-change bound to `flow_id`.
- RLS live on the real partner tables; the CI tenancy test now guards real data.
- Console greeting resolved per authenticated agency.

## Security work

| Item | Control |
|---|---|
| **Tenant isolation (#1 risk, hard gate)** | `partner_agency_id` from session only; Eloquent global scope + Postgres RLS `FORCE` + CI untenanted-query test — all green against real partner tables |
| Email-change takeover | Code bound to `flow_id`/pending record; address change needs the `flow_id`, restarts + invalidates prior codes, rate-limited |
| Partner OTP | CSPRNG, hashed, TTL, 5-attempt cap, single-use, prior-code invalidation, server-side send throttling (per-email + per-IP) |
| Partner reset | Single-use token, supersede-on-new, revokes every session across all agencies |
| Agency status gate | Sign-in refuses `pending_review`/`rejected`/`suspended`; suspend/close revokes live sessions |
| `partnerName` leak | Resolved per authenticated user, never a shared global string (one agency could otherwise see another's name) |
| Registration enumeration | Uniform response + timing whether or not the email/agency exists |
| Attestation of authority | `authorised_signatory_attested` stored, not merely validated client-side |
| Transport | `Cache-Control: no-store` on authed responses; keep `<meta robots noindex>` on the partner auth pages |

## Testing

| Test | Asserts |
|---|---|
| Cross-tenant read | A partner session cannot read another agency's rows even with a forged id in body/query; stripping the Eloquent scope yields zero rows via RLS |
| CI untenanted-query guard | Build fails if any partner-scoped query lacks an agency predicate |
| Email-change takeover (negative) | An attacker who knows a pending applicant's email cannot redirect the code without the server-side `flow_id`; rate limit enforced after 2 changes |
| Review workflow | Approval creates exactly one tenant + one owner role; rejection does not create a tenant; suspend revokes live sessions |
| Reset fan-out | A successful reset logs a multi-agency user out everywhere |
| Sign-in status gate | `pending_review`/`rejected`/`suspended` agencies cannot sign in |
| OTP hardening | Wrong code rejected; 6th attempt destroys the code; expiry enforced; resend invalidates prior |
| Enumeration | Register returns identical response + timing for existing vs unknown agency/email |

---

## Exit gate

Phase is DONE only when every box is demonstrably true in staging, verified by the named tests green in CI, and both sign-offs are given. The tenancy criterion is a **hard gate**.

- [ ] An agency can register, verify by OTP, be reviewed by staff, and on approval sign in bound to its own tenant; a rejected/suspended agency cannot sign in — walked through end-to-end on staging.
- [ ] **Cross-tenant read is impossible:** a negative test proves an authenticated partner cannot access another agency's data via any client-supplied id, and RLS returns zero rows if the app scope is removed. **(HARD GATE — not waivable.)**
- [ ] The CI untenanted-query tenancy test is present and green against the real partner tables.
- [ ] The partner email-change flow cannot redirect a verification code to an attacker address without the server-side `flow_id` (takeover test fails).
- [ ] `vfi-partner-reset.html` completes the reset and revokes all of that user's sessions across every agency.
- [ ] The console greeting resolves per authenticated agency, not from a global admin string.
- [ ] All 6 `js/partner-auth.js` REAL REQUEST markers are wired; per-page demo disclaimers removed on each partner auth page as its flow goes live.
- [ ] No BLOCK-class CI gate red (secrets, fixable Critical/High CVEs, SAST-high, tenancy test, denied licences).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Tenancy wrong → leaks a competitor's entire student book | The RLS + global scope + CI-test **triple** is mandatory and non-waivable; default-deny (`1=0`) when no tenant in context |
| Shared-login temptation (wizard collects one contact person) | Model `partner_agency_members` now; the wizard populates one owner seat, but the table supports N |
| Attribution-collision policy unresolved | Surface the product decision at P6 sign-off; it **must** be settled before Phase 7 writes attribution |
| Three cookie scopes on one origin collide | Verified in Phase 1; re-assert partner-scope isolation here with a negative test |
| Frontend sample payloads mislead the implementer | Derive payloads from the markup field inventory, not from `js/partner-auth.js` comments (`collect()` does not exist) |

## Frontend wiring (exact files/pages)

| File / page | Change |
|---|---|
| `js/partner-auth.js:761` | Wire `POST /api/partner/signin` and `POST /api/partner/register` (3-step payload assembled from all `[data-pa-step]` subtrees) |
| `js/partner-auth.js:980` | Wire partner forgot-password request |
| `js/partner-auth.js:1016` | Wire forgot-password resend |
| `js/partner-auth.js:1243` | Wire partner email verify (`flow_id`, not `?email=`) |
| `js/partner-auth.js:1318` | Wire partner email-code resend |
| `js/partner-auth.js:1364` | Wire **hardened** email-change (bound to `flow_id`) |
| `vfi-partner-login.html` | Register wizard + sign-in POST through `js/api.js`; remove `.pa-demo` disclaimer as each flow goes live |
| `vfi-partner-forgot.html` / `vfi-partner-verify.html` | Driven by `flow_id`; remove `?email=` from URL/history |
| **`vfi-partner-reset.html`** (NEW) | Token-consumption page; sibling of `vfi-partner-forgot.html` |
| `js/portal.js:313` | `logout()` calls the real session-revoke endpoint |
| `js/portal-render.js:42` | Top-bar name/avatar sourced from session `/api/session/me`, not `partnerPortal().partnerName` |
