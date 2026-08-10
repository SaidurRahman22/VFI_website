# Backend design task — PAUSED (resume later)

Status of the "design the VFI backend + write docs/" job. Paused on **2026-08-09**. Nothing was written to `docs/` before pausing (it stopped during the analysis phases). Read this to resume.

## How to resume (exact command)

Ask Claude Code to resume the workflow:

```
Workflow({
  scriptPath: "C:\\Users\\saidu\\.claude\\projects\\D--project-VFI-website\\fbc09554-1a97-4c16-abfc-45d35f4b60dc\\workflows\\scripts\\vfi-backend-blueprint-wf_1bcaf7cd-4cc.js",
  resumeFromRunId: "wf_1bcaf7cd-4cc"
})
```

- `runId`: **wf_1bcaf7cd-4cc**
- Agents that finished before the pause return **cached** instantly; only unfinished agents run live.
- Transcript / journal (to inspect what already completed):
  `C:\Users\saidu\.claude\projects\d--project-VFI-website\fbc09554-1a97-4c16-abfc-45d35f4b60dc\subagents\workflows\wf_1bcaf7cd-4cc\journal.jsonl`
- Caveat: cached results can themselves be empty — check the journal before assuming a phase is recoverable.

> Note: the workflow was large (27 agents). If a leaner run is wanted, tell Claude "do the backend docs directly, no fan-out" and it will write the stack decision + phase docs by hand instead.

## What the task produces (target `docs/` set)

`README.md`, `memory.md`, `00-executive-summary.md`, `01-tech-stack-decision.md` (ADR),
`02-architecture.md`, `03-data-model.md`, `04-api-contract.md`, `05-security-and-compliance.md`,
`06-devsecops-pipeline.md`, `07-testing-strategy.md`, `08-environments-and-runbooks.md`,
and `phases/` (README + phase-0 … phase-N).

Workflow phase order: **Discover → Propose → Judge → Decide → Design → Roadmap → Author**. Only *Author* writes files; `docs/` being empty means it paused before that.

## Preliminary engineering recommendations (my read — not yet the workflow's verdict)

Keep these even if the workflow is rerun; they're the priors.

- **Database: PostgreSQL.** Data is deeply relational (agency → students → applications → documents → wallet transactions); the wallet needs real transactional integrity. `JSONB` maps almost 1:1 onto the existing `js/store.js` content shape, easing the localStorage→server migration. Postgres full-text + `pg_trgm` carries the 100k-program search a long way before needing Elasticsearch/Meilisearch.
- **Framework: unresolved tension** — team writes vanilla JS (favours **NestJS/TypeScript**) vs. the large free admin/CRUD surface a consultancy benefits from (favours **Django**). The workflow's judge panels (security / delivery-team-fit / ops-cost) exist to settle this.
- **Non-negotiable security facts baked into the design:**
  1. **Admin panel has NO login today** — P0, must be fixed in the identity phase, not deferred.
  2. Storing **passport scans, bank statements, sponsor affidavits** → private object storage, signed short-lived URLs, encryption at rest, never on the web root.
  3. **Partner tenancy isolation** — one agency must never see another's students; enforce at the data layer.
- **Hard constraint:** the front end stays **vanilla HTML/CSS/JS, no build step**; the backend is a separate HTTP service (CORS + token strategy matter). The 8 wiring points are marked `REAL REQUEST` in `js/auth.js` and `js/partner-auth.js`.

## Frontend context the backend must serve

See the frontend orientation doc [../memory.md](../memory.md). Surfaces needing a backend: public CMS content + admin panel (currently unauthenticated), student auth + portal (profile, two document-upload packs, application tracking), partner auth + 11-page console (dashboard KPIs, program search, wallet, documents, notifications), and the two global modals (Register New Student, Request Program Options).
