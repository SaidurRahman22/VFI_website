# Phase 9 (P9) — Money Surface, Staff Back-office & Production Hardening / Launch

**What this is:** the build-and-launch spec for the wallet/ledger, PSP top-ups, application-fee debits, Flywire, the staff authoring back-office, GDPR/retention operations, and the **production cutover + hypercare + ongoing operating cadence**. **Audience:** the backend/API dev, the Filament dev, and whoever owns operations after launch.

> Money is regulated-adjacent. Append-only ledger, server-authoritative balances, idempotency, and reconciliation must be present **from the first real transaction**, not added later. Expensive AI/allied features ship **flag-OFF** with honest UI copy.

Related docs: [Phase 6 — Partner Identity & Tenancy](phase-6-partner-identity-and-tenancy.md) · [Phase 7 — Partner Console Core](phase-7-partner-console-core.md) · [Phase 5 — Student Portal](phase-5-student-portal.md) (documents) · [Phase 3 — Admin CMS](phase-3-admin-cms-write-path.md) (backup/audit) · [Backend master plan](../BACKEND_DEVELOPMENT_PLAN.md) · [agent orientation](../../memory.md)

---

## Goal

Ship the wallet/ledger + PSP top-ups + atomic application-fee debits + Flywire; complete the staff authoring back-office (document verify/reject, application status authoring, review completion, role management, cross-tenant reads); land GDPR/retention operations; and cut over to production — with AI/allied features Pennant-flagged OFF until funded.

## Duration

5–7 weeks.

## Prerequisites

| Needs | From | Why |
|---|---|---|
| Applications pipeline + `application_status_events` | [Phase 7](phase-7-partner-console-core.md) | The fee debit is atomic with application submission |
| Tenancy net green | [Phase 6](phase-6-partner-identity-and-tenancy.md) | Wallets are per-tenant |
| Document pipeline + `document_access_log` | [Phase 5](phase-5-student-portal.md) | Staff verify/reject and cross-tenant reads log against it |
| Filament admin + roles + audit + backup | [Phase 3](phase-3-admin-cms-write-path.md) / [Phase 1](phase-1-identity-spine-and-admin-lockdown.md) | The back-office is a Filament panel; step-up re-auth reuses the TOTP machinery |
| Managed Postgres + PITR, R2, Redis/Horizon, ClamAV, monitoring | [Phase 0](phase-0-platform-delivery-foundation.md) | Cutover depends on the whole platform |
| **Resolved client decisions** (below) | Client | Block final DDL and go-live |

**Client decisions that block build/launch — resolve before the money DDL is final:**

| Decision | Impact |
|---|---|
| PSP choice (bKash / Nagad / SSLCommerz / card acquirer) + hosted-checkout availability | Affects PCI scope (target SAQ-A) and webhook shape |
| Wallet currency model: BDT-only with FX at charge time vs multi-currency | Blocks final `wallets` / `wallet_transactions` DDL |
| Flywire + Élan commercial agreements + DPAs | Gates enabling those outbound flows at all |
| Commission: build the ledger surface or remove the marketing promise | A recruitment partner's primary commercial concern — must not slip silently |

---

## In scope

- Wallet + append-only ledger; PSP top-ups (webhook-credit only); atomic application-fee debits; refunds/adjustments; reconciliation.
- Flywire tuition payments + status sync (contract permitting); commission ledger surface (build-or-remove).
- Staff back-office: document verify/reject, application status/stage/note authoring, partner-application review completion, agency suspend/close, role grant/revoke, cross-tenant student read with reason-for-access.
- GDPR/ops: subject export, erasure with legal-hold exception, per-document retention clocks + deletion job, onward-disclosure record; scheduled orphan-image sweep, KPI rollups, nightly encrypted backup snapshot.
- Production cutover, monitoring/alerting, DAST baseline gate, backup-restore drill, step-up re-auth for money/roles/backup.
- AI assistant, mock interview, allied/loan, real-time notifications — all Pennant-flagged **OFF** with honest UI copy.

## Out of scope (explicit)

| Not here | Why |
|---|---|
| Streaming LLM / mock-interview / allied-loan **turned ON** | Deferred behind flags until funded; video interview is a separate data-protection decision (biometric-adjacent recorded media) |
| Draft/scheduling/preview CMS workflow | New product surface, not a port |
| Native/mobile client | Out of programme |
| Per-facet search counts | [Phase 8](phase-8-program-search.md) exit criterion (Typesense), not launch scope |

---

## Work breakdown

### 1. Wallet & ledger

| Table | Key columns |
|---|---|
| `wallets` | `id`, `partner_agency_id`, `currency`, `available_balance_minor` (NUMERIC/bigint minor units — never float), `held_minor`, `status` (`active\|frozen`), `version` (optimistic lock), `updated_at` |
| `wallet_transactions` | `id`, `wallet_id`, `direction` (`credit\|debit`), `amount_minor`, `currency`, `type` (`topup\|application_fee\|refund\|adjustment`), `txn_ref`, `ack_no?`, `student_id?`, `application_id?`, `status` (`pending\|settled\|failed\|reversed`), `balance_after_minor`, `provider_ref?`, `idempotency_key` (unique), `created_by`, `created_at` — **append-only** |
| `payment_provider_events` | `id`, `provider`, `event_type`, `provider_event_id` (unique), `payload_json`, `signature_verified`, `processed_at?`, `processing_error?` |

- Ledger is **append-only**: `REVOKE UPDATE, DELETE` on `wallet_transactions` from the app DB role.
- Balance is derived-and-checked: `available_balance_minor` mutated only inside a serialisable transaction with optimistic locking on `wallets.version`; `balance_after_minor` stored per row.

### 2. Money IN — top-ups

- `POST /api/partner/wallet/topup` creates a PSP hosted-checkout intent (idempotency key) and returns a redirect/checkout handle. The current UI has no amount field — design it.
- **Credit only from a verified, deduped, signed webhook** — never from the browser redirect:

```
webhook → verify signature → dedupe on provider_event_id (payment_provider_events)
        → BEGIN; SELECT wallet FOR UPDATE; INSERT credit txn; UPDATE balance + version; COMMIT
```

### 3. Money OUT — application-fee debit

- Debited **atomically with application submission**, honouring waivers:

```
BEGIN;
  SELECT wallet FOR UPDATE;                 -- optimistic version check
  INSERT INTO applications (...);
  INSERT INTO wallet_transactions (debit, application_fee, idempotency_key, ...);
  UPDATE wallets SET available_balance_minor = ..., version = version + 1;
COMMIT;
```

- Invariant: no debit without an application, no application without its debit. Feeds the "Payments" KPI.
- Refund/adjust: staff-only, audited, reason-coded, append-only reversal rows.

### 4. Flywire + commission (client-gated)

- `flywire_payments` (tuition, per application) + status sync — **only if** the commercial agreement + DPA are in place; otherwise keep the button navigating and flag-OFF.
- `commission_ledger` surface: **build or remove** per the client decision. Do not leave the marketing promise dangling with no surface.

### 5. Staff back-office (Filament)

| Capability | Detail |
|---|---|
| Document verify/reject | `uploaded → verified` or the new `rejected/needs-replacement` state, with a reason + audit; someone authenticated asserts a passport scan is genuine |
| Application authoring | Status transitions (writing `application_status_events`), stage label, counsellor note (reaches the student verbatim — needs authorship + timestamp + edit history) |
| Partner-application review completion | Wraps the Phase 6 approve/reject workflow |
| Agency suspend/close | Blocks sign-in, revokes member sessions, freezes wallet writes |
| Role grant/revoke | Superadmin-only, self-demotion-protected (never remove the last superadmin), audited with before/after |
| Cross-tenant student read | A distinct staff role that intentionally bypasses tenant scoping — its own `document_access_log` entries + a reason-for-access prompt on document views |

### 6. GDPR / retention ops

| Job | Detail |
|---|---|
| Subject export | Full record for a data subject (student or partner user) |
| Erasure | With a documented legal-hold exception for submitted applications |
| Retention deletion | Per-document retention clock; nightly job deletes expired **bytes**, keeps metadata + audit |
| Onward disclosure | `document_disclosures` records which third party (university/lender) received which document, when, on what basis |
| Scheduled maintenance | Orphan-image sweep, KPI rollups, nightly encrypted backup snapshot (Horizon `default`/dedicated queues + scheduler) |

### 7. Security hardening & step-up

- Money writes gated to `owner`/`finance_viewer` seat roles **+ step-up re-auth** (TOTP re-challenge).
- Role changes and backup export also require step-up + audit.
- Full audit coverage verified: `auth_events`, `content_audit_log`/`audit_log`, `document_access_log`.
- PCI kept to SAQ-A: cards only on PSP hosted checkout; nothing card-bearing touches the app.

### 8. Feature flags (ship OFF)

- AI assistant, AI mock interview, allied/loan services, real-time notifications (SSE side-service) behind Laravel Pennant, **OFF at launch**, with honest UI copy ("updated every N minutes", "coming soon"). Loan/allied outbound flows also need consent capture + DPA before they can be flipped on.

---

## Go-live

### Cutover plan

| Step | Action | Rollback |
|---|---|---|
| 1 | Freeze content edits; take a final backup snapshot (manual `kind`) | — |
| 2 | Run the one-shot migration deploy job against production (advisory-locked, never on app boot) | Roll-forward / PITR only |
| 3 | Import real content via the idempotent Artisan importer (preserve blog `legacy_id` verbatim) | Restore pre-import snapshot |
| 4 | Point DNS at production behind Cloudflare; verify same-origin `/api/*` proxy (Sanctum cookie invariant) | Revert DNS |
| 5 | Smoke: `/api/health`, admin login+TOTP, a public page from the bundle, a contact submission, a partner sign-in, one wallet top-up sandbox webhook | Halt promote; investigate |
| 6 | Enable monitoring alerts; confirm the 5 pager conditions fire on injected faults | — |
| 7 | Two-party sign-off (Architecture/DevSecOps lead + client product owner); promote is a **separate gated job** from merge-to-main | — |

- Cutover runs in a low-traffic window (Dhaka overnight). Keep the previous static-site deployment reachable for fast DNS revert during hypercare.

### Production readiness review (all must be green before promote)

- [ ] DAST baseline scan green against staging.
- [ ] Backup-restore drill current (PITR restore to scratch instance timed within RTO).
- [ ] Secrets in the manager, rotation windows documented; no `.env` in repo.
- [ ] cosign verify gate live; only signed, attested images run.
- [ ] Tenancy CI test green; RLS enforced on all partner + wallet tables.
- [ ] `Cache-Control: no-store` on authed routes; `noindex` on auth/portal/console pages.
- [ ] Rate limits live on auth + OTP + search + top-up; Turnstile on unauthenticated writes.
- [ ] Money invariant (`balance == SUM(signed ledger)`) reconciliation job scheduled and passing.
- [ ] Runbook current: deploy, rollback (roll-forward/PITR), pager conditions, restore procedure.
- [ ] AI/allied features confirmed flag-OFF with honest copy.

### Post-launch hypercare (first 2–4 weeks)

| Item | Cadence |
|---|---|
| On-call rota with the 5 pager conditions armed | 24×7 for the window |
| Daily reconciliation review (ledger invariant, PSP webhook backlog, failed top-ups) | Daily |
| Error/security monitoring triage (auth-event spikes, failed-login bursts, OTP floods, admin exports) | Daily |
| Deliverability watch (Postmark bounce/complaint rate, DKIM/DMARC) | Daily |
| Fast DNS-revert path kept warm | Standing |
| Hypercare exit review → move to steady-state cadence | End of window |

### Ongoing operating cadence (steady state)

| Activity | Cadence | Owner |
|---|---|---|
| Dependency updates (Composer/Laravel/Filament) + CVE review | Weekly (security), monthly (feature) | Backend maintainer |
| OS/container patching, digest re-pin | Monthly / on advisory | Platform owner |
| Backup-restore drill (PITR to scratch, timed) | Quarterly | Platform owner |
| Access review (admin/staff accounts, roles, active sessions, R2 tokens) | Quarterly | DevSecOps lead |
| Secrets rotation | Per documented window (≤ 90 days) | DevSecOps lead |
| DAST baseline re-run | Per release to production | CI |
| Retention/erasure job audit (spot-check deletions + legal holds) | Quarterly | Data owner |
| Filament major-version upgrade planning (maintenance tax) | As released | Backend maintainer |
| Reconciliation invariant + wallet audit spot-check | Monthly | Finance-facing staff |

---

## Deliverables

- Working wallet: top-up via PSP webhook-credit, atomic fee debit on application submit, reconciliation report, staff refund/adjust.
- Staff back-office authoring documents/applications/reviews/roles with audit and cross-tenant access logging.
- GDPR export/erasure/retention/disclosure operational with a running deletion job.
- Production launch: monitored, alerted, backup-drilled, with expensive features dark behind flags.

## Security work

| Item | Control |
|---|---|
| PCI scope | SAQ-A — cards only on PSP hosted checkout; wallet credited solely from verified, deduped, signed webhooks inside a DB transaction; idempotency keys everywhere; append-only ledger |
| Money authorisation | Writes gated to `owner`/`finance_viewer` + step-up re-auth; role changes + backup export step-up + audited |
| Retention/erasure | Legal-hold exception implemented (not bolted on); `document_disclosures` records onward transfer to EU/UK universities; SAR export |
| Audit coverage | `auth_events`, `audit_log`, `document_access_log` verified append-only and complete |
| Ops | Secrets rotation windows; production DAST baseline gate + restore drill before promote |
| Outbound flows | Loan/allied require consent capture + DPA — kept flag-OFF until legal prerequisites met |

## Testing

| Test | Asserts |
|---|---|
| Webhook idempotency | A replayed/duplicate webhook credits **once** |
| Debit atomicity | A fee debit is atomic with application create (no debit without application, no application without debit) |
| Reconciliation | `balance == SUM(signed ledger)` holds under concurrent load |
| Append-only ledger | UPDATE/DELETE on `wallet_transactions` denied to the app role |
| Money authz | Only `owner`/`finance` seats + step-up can move money; refund/adjust is staff-only + audited |
| GDPR | Subject export returns the full record; erasure respects legal hold; retention job deletes expired bytes but keeps audit |
| Launch | Backup restore-to-staging drill current; the 5 pager alerts fire on injected faults; DAST baseline passes |

---

## Exit gate

- [ ] Wallet top-ups credit **only** via verified idempotent signed webhooks; an application-fee debit is **atomic** with application submission; the nightly reconciliation invariant (`balance == SUM(signed ledger)`) passes.
- [ ] The money ledger is append-only (app role cannot UPDATE/DELETE) and money writes require the correct seat role + step-up auth.
- [ ] Staff can verify/reject documents and author application status with full audit; cross-tenant staff reads are access-logged with a reason.
- [ ] GDPR subject export, erasure-with-legal-hold, and the retention deletion job are demonstrably working.
- [ ] Production is live, monitored on the 5 pager conditions, with a current backup-restore drill and DAST baseline green; AI/allied features are flag-OFF with honest UI copy.
- [ ] Production readiness review fully green; two-party sign-off recorded; promote ran as a separate gated job.
- [ ] No BLOCK-class CI gate red (secrets, CVE, SAST-high, tenancy test, licences).

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Money is regulated-adjacent | Append-only ledger, server-authoritative balances, idempotency, reconciliation present from the first real transaction — not retrofitted |
| PSP choice affects PCI scope | Resolve PSP + hosted-checkout availability before the money DDL; target SAQ-A |
| Flywire/Élan need agreements + DPAs; currency model unresolved | Keep those flows flag-OFF; do not finalise `wallets` DDL until the currency decision lands |
| Commission promised, no surface | Build-or-remove is an explicit client call at this phase — do not let it slip silently |
| PHP-FPM weak at streaming/long uploads | Presigned direct-to-R2 for uploads (v2); AI/SSE deferred to a side service when funded; separate FPM pool for uploads so an exhausted pool cannot take the marketing site down |
| Cutover DNS/cookie misconfig | Same-origin `/api/*` proxy proven in Phase 0 and re-verified in the cutover smoke test; keep a warm DNS-revert path during hypercare |

## Frontend wiring (exact files/pages)

No new REAL REQUEST markers — all 11 were wired by [Phase 7](phase-7-partner-console-core.md).

| File / page | Change |
|---|---|
| `partner-wallet.html` `#pgAddMoney`/`#pgAddMoney2`, `#pgWalletSearch`, `.pg-wallet__chip` | Wire real top-up (hosted checkout), the paged ledger filters, and the live balance |
| `partner-dashboard.html` `.pp-btn--flywire` | Wire Flywire initiation (if the flow is enabled); otherwise leave navigating |
| Staff back-office (Filament) | Document verify/reject, application authoring, review completion, role management, cross-tenant reads — admin-side, no public page |
| `partner-dashboard.html` `.pp-assistant`, `.pp-fab`; `partner-interview.html`; `partner-allied.html` | Remain behind Pennant flags with honest copy until funded |
| Remaining per-page demo disclaimers | Removed as each real flow goes live |
