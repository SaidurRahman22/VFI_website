# Partner console browser smoke suite

**A passing API test does not mean the screen works.** That assumption shipped
broken pages to partners repeatedly, and this suite exists because of it.

The most recent example: `/api/partner/applications` returned five rows and
every backend test was green, while the partner looking at
`partner-applications.html` saw a "you currently have no active applications"
notice sitting on top of a table listing those five applications, and a **View**
button that did nothing. nginx serves the static assets with a seven-day
`Cache-Control` and no fingerprinting, but serves the HTML with no
`Cache-Control` at all — so a returning browser can hold **old HTML together
with new JS**. The cached HTML predated `#ppAppsEmpty` and `#ppAppModal`; the JS
was current. Nothing that talks to the API can see that. A browser can.

So every check here runs in real Chromium, against a real session, on the real
rendered DOM.

## Install

```sh
pip install playwright
python -m playwright install chromium
```

Playwright plus the standard library — nothing else. No test runner, no
fixtures, no config file.

## Run

The partner password has **no default and is required** — this repo is public on
GitHub and the web root is the repo root, so a literal in the source would
publish a working partner login. Supply it at run time.

Against live (the default base url):

```sh
VFI_PARTNER_PASSWORD='...' python test/ui/smoke_partner.py
```

```powershell
$env:VFI_PARTNER_PASSWORD = '...'; python test/ui/smoke_partner.py
```

Against a local XAMPP checkout:

```sh
VFI_BASE=http://localhost:8080 VFI_PARTNER_PASSWORD='...' python test/ui/smoke_partner.py
```

Exit code is `0` only when every page passed, so it can gate a deploy:

```sh
python test/ui/smoke_partner.py && git push origin main
```

| Environment variable   | Default                | Meaning                                      |
| ---------------------- | ---------------------- | -------------------------------------------- |
| `VFI_BASE`             | `http://103.14.23.151` | base url, no trailing slash                  |
| `VFI_PARTNER_EMAIL`    | `partner@vfi-fc.com`   | partner account to sign in as                |
| `VFI_PARTNER_PASSWORD` | **none — required**    | its password; never hardcode it              |
| `VFI_STRICT_EXTERNAL`  | unset                  | `1` makes third-party request failures fatal |
| `VFI_HEADED`           | unset                  | `1` shows the browser while it runs          |

Sign-in goes through the browser context: `GET /sanctum/csrf-cookie`, then
`POST /api/partner/signin` with the `X-XSRF-TOKEN` header, using the context's
own cookie jar. The pages therefore load under a genuine session cookie —
nothing is stubbed or injected.

## What it checks

Every console page (`partner-dashboard`, `partner-students`,
`partner-applications`, `partner-search`, `partner-enquiries`,
`partner-resources`) is loaded to network idle and must satisfy:

- zero console errors and zero uncaught exceptions,
- zero failed requests and zero same-origin responses of HTTP 400 or above,
- the console shell rendered (topbar and sidebar nav items) — proof `portal.js`
  ran at all,
- `#pp-main` visible with real copy in it, and the expected page heading,
- `.pp-anim` blocks actually revealed (Playwright treats `opacity: 0` as
  visible, so without this an invisible page passes every other check),
- **no empty-state copy visible at the same time as a populated table** — the
  generic form of the reported bug, applied to every page.

`partner-applications.html` additionally has to prove the whole path a partner
uses: the "no active applications" notice is hidden when rows exist, the table
carries a Documents column, and clicking the first **View** opens a *visible*
modal that names the student from the row and offers at least one file input.
That last one matters because the upload UI only exists inside that modal — when
the modal failed to open, the partner silently lost the ability to send
documents at all.

A full-page screenshot of each page (plus the opened modal) lands in
`test/ui/shots/`. They are build output and are not committed.

## Proving the suite can still fail

A green suite that cannot go red is worse than no suite. To re-verify it, use
Playwright routing to serve a deliberately broken page and confirm it fails —
for example, rewriting `id="ppAppsEmpty"` and `id="ppAppModal"` in the served
HTML reproduces the original stale-cache bug exactly, and the suite reports:

```
x empty-state copy visible above a table with 5 row(s) — "You currently have no active applications ..."
x #ppAppsEmpty is missing from the html — the js cannot hide a notice that is not there
x #ppAppModal is not in the DOM — View can never open anything
```

Aborting `js/portal.js`, stripping the `<th>Documents</th>`, or swapping the
upload `input[type=file]` for a hidden input each produce their own failure.
Do this again whenever the assertions are edited.

## Notes and limits

- Screenshots run with `reduced_motion: reduce`, which the stylesheet honours.
  Without it the `.pp-anim` entrance fade is still mid-flight when the shot
  fires and the evidence shows a half-painted page that is actually fine.
- Third-party requests (Google Fonts) are reported as warnings rather than
  failures, so the suite does not go red because someone else's CDN blipped.
  Set `VFI_STRICT_EXTERNAL=1` if you want them fatal.
- Checks are read-only apart from clicking **View**. Nothing is uploaded,
  created or deleted, so it is safe to run against live.
- `/api/partner/signin` is throttled. Several runs back to back abort with
  `signin ... failed: HTTP 429 Too Many Attempts` before any page loads — that
  is the rate limiter, not a broken screen. Wait a minute and re-run.
- If an account has no applications, the View/upload path cannot be exercised
  and the run says so as a warning — treat a console with no data as an
  untested console, not a passing one.
