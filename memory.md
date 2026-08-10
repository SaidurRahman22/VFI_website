# memory.md — agent orientation

Read this **before** exploring. It replaces a repo-wide scan. `README.md` is for humans; this is for agents.

**Project:** VFI Overseas Education — static marketing site for a Dhaka study-abroad consultancy, cloned from studies-overseas.com. 40 public pages + an admin panel + an 11-page authenticated **VFI Partner console** (`partner-*.html`, the post-login area, reskinned from coursefinder.ai).

---

## Hard constraints — do not violate

| Rule | Why |
|---|---|
| **Vanilla HTML/CSS/JS only** | User requirement, stated repeatedly. No React/Tailwind/Bootstrap/jQuery. |
| **No build step, no npm, no CDN** | Open `index.html` and it works. Never add `package.json` to the repo root. |
| **ES5 style in `js/`** | `var`, `function`, string concat. No `const`/`let`, arrow functions, or template literals. Match surrounding code. |
| **Frontend stays vanilla / no-build** | The 52 pages remain plain HTML/CSS/ES5 — no framework, no npm, no build. Still a hard rule. |
| **Backend is a SEPARATE Laravel app** | A real backend now exists in `backend/` (Phases 0–3, deployed & live) — but it never changes the frontend's nature; pages get data via an injected script seam only. |
| **Serve over HTTP to test** | Frontend-only: `python -m http.server 8777`. Full stack (same-origin API): the VPS, or XAMPP :8080 proxy locally. |

---

## Backend integration (Phases 0–5 — LIVE)

A Laravel 13 backend (in `backend/`, PHP 8.4 + PostgreSQL 16 + Redis) is deployed and running at **http://103.14.23.151** (Ubuntu VPS, nginx same-origin). Git-sync: push to GitHub (`SaidurRahman22/VFI_website`) → the VPS auto-pulls + redeploys within ~1 min. Full backend docs in `docs/`.

**How the frontend now gets data (the seam — minimal page changes):**
- `js/api.js` (new, ES5) — the single HTTP helper (`window.VFIApi`): same-origin, `credentials:include`, CSRF double-submit, 401 redirect.
- `js/store.js` — unchanged API (~36 names) but now reads `window.VFI_BOOTSTRAP` first, then localStorage, then the baked SEED (graceful fallback if the API is down).
- **`<script src="/api/content/bootstrap.js">`** is injected before `store.js` on the 51 content pages. It sets `window.VFI_BOOTSTRAP = {…}` (the DB content) synchronously, so `store.js`'s sync accessors serve real DB data. Pages stay static/CDN-cacheable.
- **Contact form** (`contact.html` → `js/main.js`): now POSTs to `/api/contact` (persists to a staff inbox); no longer discards leads.

**Backend surfaces:**
- `GET /api/content/bundle` (+ `/bootstrap.js`) — public content, faithful `""`/`[]` fall-through, ETag-cached.
- `POST /api/contact` — rate-limited, validated, URL-sanitised lead intake.
- **Admin CMS = Filament at `/manage`** (NOT the legacy `admin.html`, which is now auth-gated/superseded). Behind session auth + **mandatory TOTP** (P1). Edits the same content the bundle serves. Every write hits an append-only `content_audit_log`.
- **Admin JSON API** (all under `/api/admin/*`, session + TOTP + role-gated):
  - `content/singleton/{key}` GET/PUT — the JSONB override singletons (settings/countries/regions/servicesPage/partnerPage/partnerPortal), optimistic concurrency (`version` mismatch → 409), faithful `""`/`[]`. (content_editor+)
  - `pages` + `pages/{file}` — page-visibility toggle, server-side allow-list (`config/pages.php`), sign-in/locked pages can't be disabled. (**owner-only**)
  - `media` POST + `media/slot/{key}` PUT — image upload (magic-byte GD re-encode → content-hashed `/storage/media/*.jpg`, SVG rejected, EXIF stripped) + media-slot registry with reference-counted deletion (bundled `assets/img/*` never touched). (content_editor+)
  - `backup/export` GET + `backup/import` POST — full content export/guarded restore; restore validates + size-caps the payload and always writes a pre-restore snapshot to `storage/app/backups/` first. (**owner-only**)
- Role split: `content_editor` edits content/media; `owner` (=superadmin) also does page-visibility, backup, hard-delete. `ContentPolicy` on all 10 collections.
- Superadmin: `superadmin@vfi-fc.com` (password seeded via env; enroll TOTP on first login).

**Student auth (Phase 4 — LIVE):** the student pages (`login.html`, `student-verify.html`, `student-forgot.html`, and the NEW `student-reset.html`) now post to the backend through `window.VFIApi` (same-origin cookie session + CSRF). No more demo/fake flows.
- `POST /api/register` (name/email/password/cc+phone/agree) → creates a `pending` student, records `terms_acceptances`, returns an opaque **`flow_id`** (the email never rides in a URL). Enumeration-safe: identical response for new / unverified-resume / existing accounts.
- `POST /api/verify {flow_id,code}` + `/api/verify/resend {flow_id}` + `GET /api/verify/context` (masked email for display). OTP = CSPRNG 6-digit, argon2id-hashed, 10-min TTL, single-use, 5-attempt cap then destroyed, resend supersedes. A wrong code is rejected (killed the old "any six digits pass").
- `POST /api/login {email,password,remember}` → student session cookie; ONE generic `Invalid credentials.` for every failure (constant-time). Unverified may sign in but is flagged `must_verify` (upload/submit gating lands in P5). `GET /api/student/me`, `POST /api/student/logout` (scope-gated by `EnsureStudent`).
- `POST /api/password/reset {email}` (always 202, enumeration-safe) + `POST /api/password/reset/submit {token,password}`. Token = 32-byte CSPRNG, sha256-hashed, 45-min, single-use, supersede-on-new; a successful reset REVOKES ALL sessions.
- Hardening: per-email+per-IP rate limits on every auth write; HIBP breach-list check on register+reset (fail-open, `AUTH_BREACH_CHECK`); Cloudflare Turnstile on register/forgot/resend (`TURNSTILE_ENABLED`, off until keys exist).
- **Email delivery is DEFERRED** (no domain/Postmark yet): mail uses the `log` driver, so OTP/reset codes land in `storage/logs/laravel.log`. Flip to Postmark with `MAIL_MAILER=postmark`+token once a domain + DKIM exist. **Also set `APP_URL`** (empty on the VPS) then — it's the host in reset links.
- Student auth routes live in `routes/web.php` (web group: session+CSRF always), like admin auth — NOT `routes/api.php` (which only sessions Origin-matched requests).

**Student portal (Phase 5 — LIVE):** `student-profile.html` + `student-tracking.html` now fetch real self-scoped data via `js/student-portal.js` → `window.VFIApi`. The pages **render nothing until `/api/me` resolves and redirect on 401**; profile is **no longer persisted to localStorage** (`readSaved`/`writeSaved` are inert); both pages carry `noindex`; Log out revokes the session server-side.
- **Implicit-self, no IDOR:** every endpoint resolves the student from the session (`Student::resolveFor`), never a client id or `student_ref`. All under the `EnsureStudent` group in `routes/web.php`.
- `GET /api/me` (guard identity) · `GET /api/me/profile` (one aggregate for the 7 cards, shaped to the frontend state) · `GET /api/me/completeness` (the exact 26-item scoring, server-side) · `PUT me/profile/personal|address`, `me/qualifications`, `me/test_scores`, `me/preferences` — whole-collection replace for the two lists, per-section optimistic concurrency (stale save → 409), every client FILTER rule re-run server-side. Intake options served by the backend. Profile email is a contact field — it never rewrites the sign-in identity.
- **Documents (§3, the big one):** `document_types` (12, server-driven) + `student_documents` (adds `rejected`) + `document_files` + append-only `document_access_log`. `POST /api/me/documents/{type}` (must_verify-gated) = size cap + content-based mimetypes + magic-byte sniff → **server-UUID key on the private `documents` disk** (never the client filename) → **scan-gate** (`DocumentScanner`: built-in EICAR detection now, ClamAV INSTREAM via `DOCUMENTS_SCANNER=clamav` later) — unreadable until `scan_status=clean`; infected is quarantined + bytes dropped. `GET …/{type}/download` mints a **single-use, short-TTL opaque token**; `GET /api/documents/dl/{token}` streams once then 404s; every presign/download is logged. `DELETE` soft-deletes (blob+audit kept); verified docs are locked. sha256 idempotency avoids duplicate blobs. **Upload = commit** (no draft/save dance).
- **Tracking (read-only; write side = Phase 9):** `GET /api/me/tracking` — journey + applications + timeline + actions; server-computes journey % `(done+0.5·now)/total`, per-status counts, and `late` from a real `due_at`. Empty students get the fixed 6-stage template. `StudentTrackingSeeder::seedFor($student)` for demo data.
- **Deferrals honoured:** R2/S3 → private local `documents` disk (swap the disk to s3 via env, app code unchanged); ClamAV → built-in EICAR scanner (env-swap to clamd). Separate upload FPM pool not set up (single pool).

**Security (mandatory, enforced at the model layer so any write path is covered):** blog body = plain text (`strip_tags`); `ppQuicklinks`/`ppDocs` URLs scheme-allow-listed (`App\Support\UrlGuard`); tenancy scope + RLS (Postgres); argon2id; append-only audit/auth logs; uploads validated by magic-bytes not extension; backup restore is snapshotted + owner-gated; OTP/reset tokens hashed at rest + single-use; enumeration-safe register/login/forgot; server-side rate limits; student endpoints implicit-self (no IDOR); documents scan-gated on a private disk with single-use signed downloads + append-only access log. 151 backend tests passing.

**Local dev seed:** `php artisan content:import backend/database/content/demo.json` (the current marketing content, captured from the `store.js` SEED). Idempotent (upsert on `legacy_id`).

---

## File map

```
*.html            40 public pages + admin.html + 11 partner-*.html (console)
css/style.css     2478 L  design system + every shared component + scroll animations
css/admin.css      219 L  admin panel only
css/student-auth.css  1700 L  login + student-forgot + student-verify   — every class prefixed sa-
css/partner-auth.css  1487 L  vfi-partner-login + -forgot + -verify     — every class prefixed pa-
css/student-portal.css 388 L  student-profile + student-tracking        — every class prefixed sp-
css/partner-portal.css 557 L  VFI Partner console (partner-*.html)       — every class prefixed pp-
js/store.js        554 L  content store (localStorage JSON + IndexedDB images)
js/portal.js       428 L  partner console shell: sprite + top bar + sidebar + both modals + char rules
js/portal-render.js 151 L  overlays admin-managed content onto partner-*.html (like render.js, for the console)
js/site.js         235 L  SVG sprite + header + footer, injected into every page
js/main.js         634 L  all shared interactions
js/render.js       575 L  applies stored content to public pages (incl. the blog article)
js/admin.js       1086 L  admin panel — collapsible menu groups; public site + partner console
js/auth.js        1040 L  student auth — login + student-forgot + student-verify
js/partner-auth.js 1248 L  partner auth — vfi-partner-login + -forgot + -verify
js/student-portal.js 917 L  student-profile + student-tracking
```

**Page groups:** home/about/contact/gallery/events/blogs · blog-post (article, reads `?id=`) · services + 5 service pages · 6 `study-in-*` + europe/asia/destinations · careers/news/csr · for-institutions/partners/franchisee · terms/privacy/payment-terms · student auth: login + student-forgot + student-verify · student portal: student-profile + student-tracking · partner auth: vfi-partner-login + -forgot + -verify · vfi-partner · **partner console** (post-login): partner-dashboard, -students, -applications, -search, -wallet, -enquiries, -resources, -email-updates, -notifications, -interview, -allied.

---

## Architecture

**Script order on every public page — never reorder:**
```html
<div id="site-header"></div> … <div id="site-footer"></div>
<script src="js/store.js"></script>   <!-- defines window.VFI -->
<script src="js/site.js"></script>    <!-- injects sprite + header + footer -->
<script src="js/main.js"></script>    <!-- wires interactions -->
<script src="js/render.js"></script>  <!-- overlays stored content, re-hooks -->
```

`site.js` also **deletes nav/footer links to pages disabled** in Pages On/Off.

**The six auth pages are exceptions:** login + student-forgot + student-verify + vfi-partner-login + vfi-partner-forgot + vfi-partner-verify have no shared header/footer, so they load only `store.js` + their own auth script (`auth.js` drives the three student pages, `partner-auth.js` the three partner pages), inline their own SVG sprite, and carry their own copy of the "page switched off" notice (guarded via `data-sa-page` / `data-pa-page` on `<body>`). Their `sa-`/`pa-` prefixes mean **no rule in `style.css` can reach them** — that isolation is deliberate, keep it. The two student **portal** pages (student-profile, student-tracking) are normal chrome-carrying pages, prefixed `sp-`.

**The VFI Partner console** (`partner-*.html`, reached when sign-in succeeds on vfi-partner-login) is its own app shell — a fixed teal top bar + collapsible sidebar, NOT the public header/footer. Each page carries `<body class="pp" data-pp-page="KEY">`, a single `<div id="pp-chrome"></div>` placeholder, `<main class="pp-main">`, and loads only `store.js` + `js/portal.js`. **`portal.js` injects the whole shell** (SVG sprite `#pi-*`, top bar, sidebar with nav highlight from `data-pp-page`, both global modals — Register New Student & Request Program Options), and wires: sidebar collapse (localStorage `pp_collapsed`), the mobile drawer, notification + account dropdowns, `data-pp-open="register-student|request-program"` triggers, per-field char rules (`data-pp-only="digits|name|alnum"`, caret-preserving), `window.VFIToast(msg,kind)`, and `.pp-anim` scroll reveals. Every class is prefixed `pp-` (isolated from style.css). Palette continues the teal/emerald partner-auth identity. To add a console page: copy partner-dashboard.html's skeleton, set `data-pp-page`, put page-specific CSS in a `<style>` scoped under a page-unique wrapper class. **Console pages are NOT in Pages On/Off** and portal.js has no page-off guard (they're an authenticated area, not public nav).

**Console content is admin-managed.** Every console page also loads `<script src="js/portal-render.js">` right AFTER portal.js. `portal-render.js` is the console's `render.js`: it reads the store and overwrites a container's content **only when the store data is non-empty** (blank = keep the page's built-in demo), via `[data-ppr="KEY"]` hooks placed on the pages. KEYs: `welcome, tierName, benefits, quicklinks, managers, updates` (dashboard) · `docs` (resources) · `emails` (email-updates) · `notifs` (notifications) · `loanText/accomText/testprepText` (allied). It also updates the injected top-bar name/avatar from `partnerPortal().partnerName`. The admin panel's **Partner Console** menu group edits this content — one central admin manages both the public site and the console.

**Body attributes:** `data-page` (nav highlight) · `data-header="overlay"` (transparent header over a coloured hero) · `data-country` / `data-region` / `data-svcpage` / `data-partner` (tells `render.js` which applier to run) · `data-article="blog"` (blog-post.html, reads `?id=`).

---

## Store API — `window.VFI` (js/store.js)

```
SEED  data  save  reset
list get put remove            generic collections
settings saveSettings          media setMedia
country saveCountry            region saveRegion
servicesPage saveServicesPage  partnerPage savePartnerPage
partnerPortal savePartnerPortal partner-console text object
pageEnabled setPage baseName   page on/off
getImage putImage delImage uploadImage    IndexedDB, auto-downscaled
exportAll importAll            backup
uid fmtDate fmtDay esc storageOK
```
`SEED` keys: `settings, events, blogs, news, photos, countries, regions, servicesPage, partnerPage, pages, media` + partner-console: `ppManagers, ppUpdates, ppQuicklinks, ppDocs, ppEmails, ppNotifs` (generic `list/get/put/remove` collections) and `partnerPortal` (a settings object; use `partnerPortal()`/`savePartnerPortal()`).
All strings/arrays seed **empty** — a blank field means "keep the page's built-in content", never "blank the page".

**Other globals** (call after replacing DOM): `VFIInitReveal(scope?)` · `VFIInitAccordions()` · `VFIAutoSlide(el)` · `VFIFilterJobs()`.

---

## Render hooks

`render.js` reads the store and writes into data-attributes. One prefix per page family:

| Prefix | Page family | Text field / Repeater |
|---|---|---|
| `data-render` | home, events, blogs, gallery | `data-render="events\|fevents\|blogs\|news\|photos"` |
| `data-media` | home image slots | — |
| `data-cfield` / `data-crender` | `study-in-*` | country pages |
| `data-rfield` / `data-rrender` | europe, asia | region pages |
| `data-sfield` / `data-srender` | services.html | — |
| `data-pfield` / `data-prender` / `data-pf` | vfi-partner.html | `data-pf` = field inside a repeater row |
| `data-bp` | blog-post.html | article slots; body parsed from the `blogs` `body` field (`## `→h2, `- `→li, `> `→quote) |
| `data-ppr` | partner-*.html (console) | applied by **portal-render.js** (not render.js); `managers/updates/quicklinks/benefits/docs/emails/notifs` replace a container's innerHTML from a store collection when non-empty; `welcome/tierName/loanText/…` set textContent |

**Repeater pattern:** `render.js` clones the container's **first existing child** as a template, then fills `[data-pf="…"]` by `textContent`. So the built-in markup *is* the template — changing it changes what admin-driven content renders as. Anything not a `[data-pf]` (e.g. `data-dept`, alternating `is-flip`, ordinal numbers) must be re-applied explicitly per clone, or every row inherits row 1's value.

---

## Task → file routing

| To change… | Edit |
|---|---|
| Nav, mega-menus, footer, SVG sprite | `js/site.js` (one place, all pages) |
| Colours, spacing, any shared component | `css/style.css` (tokens at top) |
| Scroll animations | `css/style.css` bottom block + `initReveal`/`autoTag` in `main.js` |
| Admin: a new editable section | `js/store.js` (SEED + accessors) → `js/admin.js` (view) → `js/render.js` (applier) |
| Admin: a new CRUD list | add a `SCHEMA` entry in `js/admin.js`. Non-`title`/no-image lists set `titleKey` (row title field), `metaKeys` (row sub-line fields), `flat:true` (no thumbnail). The generic `renderList`/`openForm`/modal + `list/get/put/remove` do the rest — no new logic. |
| Admin: nav menu / submenu | `admin.html` — nav is `.ad__group` blocks (collapsible `.ad__grouphd` header + `.ad__sub` of `.ad__navbtn[data-view]`). `show()` auto-opens the active item's group. |
| Manage partner-console content | Admin **Partner Console** group (`ppManagers/ppUpdates/ppQuicklinks/ppDocs/ppEmails/ppNotifs` lists + `ppText` form). It writes the store; `js/portal-render.js` + `data-ppr` hooks show it on the console. Blank = built-in demo. |
| Add a page to the on/off toggle | `SITE_PAGES` array in `js/admin.js` (7 groups; 3rd tuple item `true` = locked "Always on", no toggle) |
| Student auth (sign-in/register, reset, verify) | `login.html` / `student-forgot.html` / `student-verify.html` + `css/student-auth.css` + `js/auth.js` |
| Partner auth (sign-in/register, reset, verify) | `vfi-partner-login.html` / `vfi-partner-forgot.html` / `vfi-partner-verify.html` + `css/partner-auth.css` + `js/partner-auth.js` |
| Student portal | `student-profile.html` / `student-tracking.html` + `css/student-portal.css` + `js/student-portal.js`. Entry: `#saDonePortal` in login's success state (`auth.js` reveals it). Two upload packs on the profile — application `documents` + visa `visaDocuments` — share `paintPack`/`wirePack`/`docItem(def,draft)`; a new pack = defs + SEED key + a `PACK_*` config + a `[data-sp-form]` card. Files are recorded by name only (front-end demo). A sticky **side nav** (`renderSideNav` → `#spSide` inside `.sp-shell`) is injected on both pages (profile/documents/visa/tracking/logout); tracking wraps its 3 sections in `.sp-shellwrap` and neutralizes their section chrome via CSS. |
| Partner console shell (nav, top bar, sidebar, both modals, char rules, toast) | `js/portal.js` (one place, all `partner-*.html`) |
| Partner console styles / any `pp-` component | `css/partner-portal.css` (tokens at top) |
| Add a partner console page | copy `partner-dashboard.html` skeleton, set `data-pp-page`, page-specific CSS in a `<style>` scoped under a unique wrapper class. Add its nav entry to the `NAV` array in `js/portal.js` if it belongs in the sidebar. Entry into the console: `partner-auth.js` sends a successful sign-in to `partner-dashboard.html` |
| Blog article | `blog-post.html` + `data-bp` appliers in `js/render.js`; body/author/readTime fields in the `blogs` schema (`js/admin.js`, `js/store.js`) |
| Per-field input rules | `data-sa-mask` / `data-pa-chars` = `digits\|name\|email\|nospace`, enforced on keypress + paste + input (caret preserved). Phone is `type="tel"` + `inputmode="numeric"`, **never** `type="number"` (spinners + drops leading 0) |
| Add/replace real photos | Library in `assets/img/*.jpg` (free-license Unsplash — students, campus, team/office, advisor, support, handshake, 6 city skylines). Background photo slot: add class `has-photo` + inline `style="background-image:url('assets/img/X.jpg');background-size:cover;background-position:center"` (mirrors `render.js applyMedia`; admin upload still overrides). Card covers: set an item's `imgId` to a path (`assets/img/X.jpg`) — `store.js getImage` now resolves path/URL imgIds directly. Page banner: add `<div class="page-hero__bg" style="background-image:url(...)">` as first child of a `.page-hero`/`.hero` (overlay keeps text legible). |
| Connect a real backend | search `REAL REQUEST` in both auth scripts (sign-in, register, send/resend reset, resend/check code) |

---

## Landmines — these have all bitten before

1. **`$` vs `$$`.** `$` = querySelector (one), `$$` = querySelectorAll (array). Writing JS through a **shell heredoc mangles `$$` into `$`**, producing `x.forEach is not a function`. This has recurred 5+ times. **Write JS with the Write/Edit tools, never through `bash -c` with `$$` in it.**

2. **`clip-path: inset(0 …)` cuts box-shadows.** It clips to the *sharp* border box, so a rounded card's shadow survives only in the corner squares → grey wedges, and a clipped parent cuts its children's shadows. If you must animate a clip, end at a **negative** inset (`inset(-40%)`). Never put clip-path in a shared "settled" rule.

3. **Don't add `will-change` broadly.** It promotes a compositing layer per element (memory + shadow artefacts). These are one-shot transitions; the browser is fine without it.

4. **The reveal safety net must stay viewport-scoped.** It once revealed *everything* after 1200 ms, so nothing ever animated on scroll. It may only reveal elements already in view.

5. **`behavior: "auto"` is not instant.** The root sets `scroll-behavior: smooth`, and `auto` defers to it. Use `"instant"` for hash-landing corrections.

6. **Re-hook after replacing DOM.** `render.js` must call `VFIInitReveal` / `VFIInitAccordions` / `VFIAutoSlide` / `VFIFilterJobs`, or new nodes sit at `opacity: 0` forever or lose their handlers.

7. **Wire accordions in one place.** Both the initial pass and the re-hook must set `_wired`; otherwise the re-hook attaches a second click handler and panels open-then-close instantly.

8. **`<figure>`/`<details>` have default margins** that silently break card layouts.

9. **Hidden-element traps:** a `.reveal`-class element inside an already-tagged ancestor keeps `opacity: 0` with no observer watching → invisible forever. Always strip the class if you skip tagging it. Also: toasts / `[role="status"]` sit at `opacity: 0` **by design** until triggered — don't count them as stuck reveals.

10. **A reused Chrome `--user-data-dir` caches JS on disk.** A test that reloads a page you just edited can silently run the *old* file → phantom failures (e.g. "Pages On/Off renders 0 rows"). Send `Network.setCacheDisabled{true}` after connecting, or use a fresh profile dir per run.

11. **The success panel toggles via a class, not `display`.** `#saDone` is `display: grid` always; `showDone()` adds `.sa-on` (opacity/visibility). Assert on `classList.contains('sa-on')`, not computed display. Same shape for wizard steps (`.pa-on` on `[data-pa-step]`).

12. **Don't `fetch()` the server in a loop while a CDP WebSocket is open.** ~15 rapid GETs to `python -m http.server` alongside the debugger socket trips an undici internal assertion (`assert(!this.paused)`) that crashes the Node harness mid-run and is **not** catchable by your `try/catch`. For local-file existence checks use `fs.existsSync(ROOT + "/" + file)`, not `fetch`.

13. **The partner console shell is injected, so `#pp-chrome` is gone after load.** `portal.js` does `insertAdjacentHTML` then removes the placeholder. Assert on `.pp-top` / `.pp-side` (the real injected nodes) and the active item via `.pp-nav__item.is-active .pp-nav__label`, not on `#pp-chrome`. Sidebar-collapsed state persists in `localStorage` across a shared `--user-data-dir` — reset it before width assertions.

14. **Grid/flex items overflow on narrow phones (`min-width: auto`).** A grid/flex child won't shrink below its content's min-content width by default, so on a ~390px phone one child can push a track a few px past the viewport (a sliver of horizontal scroll). Fix: `min-width: 0` on the grid/flex items (`.pp-dash > *`, `.pp-grid > *`, flex inputs). The text wraps instead.

15. **Overflow tests: use `documentElement.clientWidth`, not `window.innerWidth`.** Under CDP mobile emulation `window.innerWidth` reports the *layout* viewport, which already includes the overflow — so `scrollWidth > innerWidth` reads false and hides a real few-px overflow. Compare `scrollWidth` against `clientWidth`, and disable the cache (landmine 10) or a stale CSS masks the fix.

16. **Never `display:flex`/`grid` a line that contains mid-sentence inline elements** (`text <b>x</b> text`, `Use <a>Backup</a> to…`). Flex/grid turns each text run AND each inline element into a separate item; on a wide screen they sit in a row and look like one sentence, but on a narrow phone they collide into overlapping columns. Use normal inline flow and position the leading icon absolutely (`li{position:relative;padding-left:28px} li .ic{position:absolute;left:0}`). Icon-then-plain-text (one text run) is fine; two+ text runs in a flex row is the tell. `grid/flex min-width:0` won't help — it's an item-splitting bug, not a shrink bug.

17. **`mobcheck.mjs`'s OVERLAP check has a false-positive mode: wrapped inline siblings in normal (non-flex) prose.** `getBoundingClientRect()` on an inline element that line-wraps returns the *union* box of all its line fragments. Two `<a>`/`<b>` siblings in a plain `<p>` — e.g. `.contact__list`'s two `tel:` links separated by " · " — can report a bbox "overlap" even though nothing actually touches on screen. Screenshot the flagged spot before "fixing" it; if it's plain inline text (not `display:flex/grid` — that's landmine 16 instead), it's almost certainly this artifact. Real bug found this way once: a bare `-` in a phone number is a valid line-break point, so `+880 9600-000000` could wrap mid-number — fixed with `white-space:nowrap` on `.contact__list a[href^="tel"]` (`css/style.css`). Also: the wave-hero search/content card (`.dsearch`, `.page-hero__inner`+wave) *intentionally* overlaps its hero section via negative margin — `section.rhero ∩ div.container` / `section.page-hero ∩ div.container` on asia/europe/universities-style pages is by design, not a bug.

---

## Testing

No test framework. Verification is **headless Chrome over CDP** (WebSocket, Node 24 — no puppeteer):

```bash
python -m http.server 8777 &
node scratchpad/<test>.mjs     # spawn chrome --headless=new --remote-debugging-port=NNNN
```
Standard checks per page: horizontal overflow at 1440 and 390, unresolved `<use href="#…">`, elements stuck at `opacity: 0`, shadow-cutting clip-paths, `Runtime.exceptionThrown`, exactly one `<h1>`, header+footer present (except the **six** chrome-less auth pages, and the **11 `partner-*.html`** console pages which carry their own `.pp-top` + `.pp-side` shell instead — keep the harness's `NO_CHROME` list in sync). Drive char-restriction / OTP tests with real CDP key events (`rawKeyDown` + `char` — not `keyDown` *with* text, which double-inserts).

**Assert observable outcomes, not absence of errors.** Every real bug in this project was found by checking that a thing *actually happened* — a panel opened, opacity reached 1, a filter returned 2 rows. Several were masked by `try/catch` or by a throw that hid a worse bug underneath.

Known false positives: reveal animations need **~1600 ms** to settle (sampling at 1250 ms shows them faded); smooth-scroll needs to finish before measuring; `:focus` styles need `Emulation.setFocusEmulationEnabled`; skip links measured while parked off-screen report meaningless contrast.

---

## Conventions

- Brand is **VFI**. Never write "KC" or "coursefinder" — this is a reskin, and those are the source site's marks.
- Placeholder contacts: `dhaka@vfi-edu.com`, Gulshan 1, Dhaka 1212. Phones are `+880 1700-000000` style.
- Dates in seed content are 2026.
- All copy is original. Don't invent named partners, real charities, guaranteed earnings or commission percentages.
- Both auth pages state plainly that they're front-end demos that sign nobody in. Keep that until a backend exists.
- Everything animated must be disabled under `prefers-reduced-motion` — except a busy spinner, which is *slowed*, not frozen.
- **Images are free-license only** (Unsplash/Pexels/Picsum), stored locally in `assets/img/`. Never scrape/embed arbitrary copyrighted images — this is a real business site. Photos live behind designed slots (`has-photo`), card `imgId` paths, and `.page-hero__bg` banners; all are admin-overridable and seed to sensible defaults.

---

## Backend development plan — context for agents

**Status:** Planning complete. Full plan in `docs/BACKEND_DEVELOPMENT_PLAN.md`. No backend code exists yet.

### Decided technology stack

| Layer | Choice | Notes |
|---|---|---|
| **Runtime** | Node.js | Same ecosystem as frontend JS |
| **Framework** | NestJS (TypeScript) | Enterprise modules, DI, guards, pipes, interceptors |
| **Database** | PostgreSQL 16+ | ACID for wallet/financials, JSONB for CMS, RLS for multi-tenant isolation |
| **ORM** | Prisma | Type-safe queries, declarative schema, auto-generated types |
| **Cache / Sessions** | Redis 7+ | JWT blacklist, rate limiter, query cache, pub/sub |
| **Object Storage** | AWS S3 / MinIO | Student documents, images, gallery — pre-signed URLs |
| **Email** | SendGrid / AWS SES | OTP, password reset, status updates |
| **SMS** | Twilio / local BD gateway | Phone OTP for +880/+977/+94 numbers |
| **Task Queue** | BullMQ (Redis) | Email sending, image processing, virus scanning |
| **Containers** | Docker + Docker Compose | Dev parity, reproducible builds |
| **CI/CD** | GitHub Actions | Lint → Test → Build → Scan → Deploy |
| **Reverse Proxy** | Nginx | SSL termination, rate limiting, static serving |

### Phase roadmap (25 weeks / ~6 months)

| Phase | Duration | Focus |
|---|---|---|
| **0** | Wk 1–2 | Foundation: NestJS scaffold, Docker, CI/CD, security scanning |
| **1** | Wk 3–5 | Database: Prisma schema (22 tables), seed migration from `store.js` SEED data, core middleware |
| **2** | Wk 6–8 | Auth: JWT + refresh tokens, student/partner/admin auth, OTP, RBAC, rate limiting |
| **3** | Wk 9–11 | Student backend: profile CRUD, document upload (S3), application tracking, university search |
| **4** | Wk 12–14 | Partner backend: dashboard, student management, wallet, resources, notifications |
| **5** | Wk 15–17 | Admin/CMS backend: content CRUD, media management, page toggles, backup, user management |
| **6** | Wk 18–20 | Integration: email/SMS services, WebSocket notifications, public API (replaces `render.js`), search, reporting |
| **7** | Wk 21–22 | Security hardening: OWASP audit, encryption at rest, GDPR, pen testing, audit logging |
| **8** | Wk 23–25 | Production: deploy, monitoring (Prometheus/Grafana), load testing, launch checklist |

### Frontend integration points

All backend wiring stubs are marked `/* REAL REQUEST */` in `js/auth.js` and `js/partner-auth.js`. The migration order is:

1. `js/auth.js` → `auth` module (Phase 2)
2. `js/partner-auth.js` → `auth` module (Phase 2)
3. `js/student-portal.js` → `students`, `applications`, `documents` modules (Phase 3)
4. `js/portal.js` + `js/portal-render.js` → `partners` module (Phase 4)
5. `js/admin.js` → `admin` module (Phase 5)
6. `js/render.js` + `js/store.js` → `public` API (Phase 6)
7. `js/site.js` → `public/settings` (Phase 6)
8. `js/main.js` → No backend change needed

### Backend project location

The backend will live in a **separate directory** (e.g., `vfi-backend/` or `backend/`) with its own `package.json`, `tsconfig.json`, and Docker setup. The frontend repo (`VFI_website/`) remains vanilla HTML/CSS/JS with **no npm, no build step**. The `memory.md` constraint "No backend" now becomes "No backend **in this repo**" — the backend is a separate codebase that serves API endpoints consumed by the frontend JS files.

### User roles identified (4 tiers)

1. **Student** — Profile, documents, applications, tracking
2. **Partner** (Sub-Agent/Franchise/Institution) — Dashboard, student management, wallet, resources
3. **Admin** — CMS, user management, content, settings
4. **Super Admin** — Full system control, audit logs, partner approvals

### Database entities (22 tables)

User, Session, StudentProfile, Academic, TestScore, StudentPreference, Document, Application, JourneyStep, TimelineEntry, ActionItem, University, Program, Partner, PartnerStudent, WalletTransaction, Enquiry, BlogPost, Event, NewsItem, GalleryPhoto, PageContent, MediaSlot, SiteSettings, Notification, AuditLog.
