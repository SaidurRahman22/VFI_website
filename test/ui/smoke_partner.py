#!/usr/bin/env python3
"""
smoke_partner.py — real-browser smoke test for the VFI partner console.

Every check in this file exists because an API-level test passed while the
screen was broken. The console is a static HTML shell that js/portal-data.js
fills in at runtime, so "the endpoint returned 5 applications" says nothing
about whether the partner could see them, or click them. Only a browser can
answer that, so this suite drives a real one.

It signs in the way the browser does — GET /sanctum/csrf-cookie, then POST
/api/partner/signin through the browser context's own cookie jar — so the
session cookie the pages use is genuine and nothing is stubbed.

Usage:
    python test/ui/smoke_partner.py
    VFI_BASE=http://localhost:8080 python test/ui/smoke_partner.py

Environment:
    VFI_BASE               base url                (default http://103.14.23.151)
    VFI_PARTNER_EMAIL      partner login           (default partner@vfi-fc.com)
    VFI_PARTNER_PASSWORD   partner password        (required — no default, see below)
    VFI_STRICT_EXTERNAL    1 = third-party request failures are fatal too
    VFI_HEADED             1 = show the browser (debugging)

The password has no default on purpose. This repo is public on GitHub and the
web root is the repo root, so a literal here would publish a working partner
login twice over. Supply it from the environment at run time.

Exit code is 0 only when every page passed, so this can gate a deploy.
"""

import json
import os
import sys
import urllib.parse

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, "shots")

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151").rstrip("/")
EMAIL = os.environ.get("VFI_PARTNER_EMAIL", "partner@vfi-fc.com")
PASSWORD = os.environ.get("VFI_PARTNER_PASSWORD", "")
STRICT_EXTERNAL = os.environ.get("VFI_STRICT_EXTERNAL") == "1"
HEADED = os.environ.get("VFI_HEADED") == "1"

NAV_TIMEOUT = 45000
ACT_TIMEOUT = 15000

# The whole console, as it actually exists on disk. Every entry was checked
# against the repo root — an assertion against a 404 is worse than no test,
# because a missing page then reads as a passing one.
PAGES = [
    {"file": "partner-dashboard.html", "heading": "Dashboard"},
    {"file": "partner-students.html", "heading": "Students"},
    {"file": "partner-applications.html", "heading": "Applications"},
    # the search screen leads with a marketing hero, not the standard page head
    {"file": "partner-search.html", "heading": "Explore over 100,000+ Programs",
     "heading_sel": ".pg-search__hero h1"},
    {"file": "partner-enquiries.html", "heading": "Enquiries"},
    {"file": "partner-resources.html", "heading": "Learning Resources"},
]

# The empty-state copy the console uses, in the shapes it uses it. This is the
# generic form of the partner-applications bug: a "you have nothing here"
# panel sitting on top of a table that is listing things.
EMPTY_COPY = (
    r"\b(?:you\s+(?:currently\s+)?have\s+no|there\s+are\s+no|we\s+found\s+no|no)\s+"
    r"(?:active\s+|current\s+|open\s+|new\s+|saved\s+|matching\s+|recent\s+)*"
    r"(?:applications?|students?|enquir(?:y|ies)|results?|records?|resources?|documents?|rows?)\b"
)

# Everything visible on a healthy console page: the injected sidebar plus real
# copy in the work surface. Below this, the page is a blank shell.
MIN_MAIN_TEXT = 120


class Result(object):
    """One page's verdict. Failures fail the run; warnings only get printed."""

    def __init__(self, name):
        self.name = name
        self.failures = []
        self.warnings = []
        self.notes = []

    def fail(self, msg):
        self.failures.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)

    def note(self, msg):
        self.notes.append(msg)

    @property
    def ok(self):
        return not self.failures


# ---------------------------------------------------------------- sign-in

def sign_in(context):
    """Authenticate through the browser context so the pages get a real session.

    Uses context.request, which shares the context's cookie jar — a separate
    HTTP client would authenticate a session the pages never see.
    """
    host = BASE
    try:
        r = context.request.get(host + "/sanctum/csrf-cookie")
    except Exception as exc:
        # a wrong VFI_BASE is the usual cause, and Playwright's own error for it
        # is a wall of request headers that buries the one useful line
        raise SystemExit("cannot reach %s — %s" % (host, str(exc).splitlines()[0][:160]))
    if not r.ok:
        raise SystemExit("csrf-cookie failed: HTTP %d" % r.status)

    token = None
    for c in context.cookies(host):
        if c["name"] == "XSRF-TOKEN":
            # Laravel percent-encodes the cookie value; the header wants it raw
            token = urllib.parse.unquote(c["value"])
    if not token:
        raise SystemExit("no XSRF-TOKEN cookie after /sanctum/csrf-cookie")

    r = context.request.post(
        host + "/api/partner/signin",
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-XSRF-TOKEN": token,
            "Origin": host,
            "Referer": host + "/vfi-partner-login.html",
        },
        data=json.dumps({"email": EMAIL, "password": PASSWORD}),
    )
    if not r.ok:
        raise SystemExit("signin as %s failed: HTTP %d — %s" % (EMAIL, r.status, r.text()[:300]))
    return r.json()


# ---------------------------------------------------------------- watchers

def watch(page, res):
    """Record everything the browser complains about while a page loads."""
    origin = urllib.parse.urlparse(BASE).netloc

    def same_origin(url):
        return urllib.parse.urlparse(url).netloc == origin

    def on_console(msg):
        if msg.type == "error":
            res.fail("console error: %s" % msg.text[:220])

    def on_pageerror(err):
        res.fail("uncaught exception: %s" % str(err).splitlines()[0][:220])

    def on_requestfailed(req):
        why = req.failure or "unknown"
        line = "request failed: %s %s (%s)" % (req.method, req.url[:160], why)
        (res.fail if same_origin(req.url) else res.warn)(line)

    def on_response(resp):
        if resp.status >= 400:
            line = "HTTP %d: %s %s" % (resp.status, resp.request.method, resp.url[:160])
            (res.fail if same_origin(resp.url) or STRICT_EXTERNAL else res.warn)(line)

    page.on("console", on_console)
    page.on("pageerror", on_pageerror)
    page.on("requestfailed", on_requestfailed)
    page.on("response", on_response)


# ---------------------------------------------------------------- probes

# Runs in the page. Finds the INNERMOST elements whose text matches an
# empty-state phrase — every ancestor up to <body> also "contains" that text,
# so without the innermost filter this reports the whole document.
FIND_EMPTY_COPY = """
(pattern) => {
  const re = new RegExp(pattern, 'i');
  const norm = (el) => (el.textContent || '').replace(/\\s+/g, ' ').trim();
  const hits = [];
  document.querySelectorAll('body *').forEach((el) => {
    if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE' || el.tagName === 'TEMPLATE') return;
    if (!re.test(norm(el))) return;
    for (const kid of el.querySelectorAll('*')) { if (re.test(norm(kid))) return; }
    const cs = getComputedStyle(el);
    const box = el.getBoundingClientRect();
    hits.push({
      text: norm(el).slice(0, 180),
      id: el.id || '',
      cls: String(el.className || '').slice(0, 90),
      visible: !el.hidden && cs.display !== 'none' && cs.visibility !== 'hidden'
               && cs.opacity !== '0' && box.width > 0 && box.height > 0
    });
  });
  return hits;
}
"""

# A placeholder row ("No data", one cell spanning the table) is not real data,
# so it must not count as a populated table.
COUNT_TABLE_ROWS = """
() => {
  let n = 0;
  document.querySelectorAll('table tbody tr').forEach((tr) => {
    const cells = tr.querySelectorAll('td');
    if (cells.length <= 1) return;
    if (tr.offsetParent === null) return;
    n += 1;
  });
  return n;
}
"""


def assert_no_contradicting_empty_state(page, res):
    """The generic form of the reported bug, applied to every console page.

    An empty-state notice and a populated table cannot both be true. When they
    are both on screen, the html and the js that fills it have drifted apart —
    which is exactly what a stale cached page looks like.
    """
    rows = page.evaluate(COUNT_TABLE_ROWS)
    hits = page.evaluate(FIND_EMPTY_COPY, EMPTY_COPY)
    showing = [h for h in hits if h["visible"]]

    if rows > 0 and showing:
        for h in showing:
            res.fail(
                'empty-state copy visible above a table with %d row(s) — '
                '<%s%s> "%s"' % (
                    rows,
                    ("#" + h["id"]) if h["id"] else "",
                    (" ." + h["cls"].split()[0]) if h["cls"] else "",
                    h["text"][:110],
                )
            )
    if rows:
        res.note("%d table row(s)" % rows)
    return rows


def assert_rendered(page, spec, res):
    """The page is actually a console screen, not a blank work surface."""
    if not page.url.startswith(BASE):
        res.fail("navigated away to %s — the session was rejected" % page.url)
        return
    if "login" in page.url:
        res.fail("bounced to the login page (%s) — auth was lost" % page.url)
        return

    # portal.js injects the shell before #pp-chrome and then removes that
    # placeholder, so the nav is a sibling of where it was — not inside it.
    if page.locator(".pp-side .pp-nav .pp-nav__item").count() == 0:
        res.fail("console chrome never rendered (no .pp-side .pp-nav__item) — portal.js did not run")
    if page.locator(".pp-top").count() == 0:
        res.fail("console topbar never rendered (no .pp-top)")

    main = page.locator("#pp-main")
    if main.count() == 0:
        res.fail("no #pp-main on the page")
        return
    if not main.first.is_visible():
        res.fail("#pp-main is not visible")
        return

    text = (main.first.inner_text() or "").strip()
    if len(text) < MIN_MAIN_TEXT:
        res.fail("work surface is effectively blank (%d chars of visible text)" % len(text))

    sel = spec.get("heading_sel", ".pp-head__title")
    head = page.locator(sel).first
    if head.count() == 0 or not head.is_visible():
        res.fail("main heading %s is missing or hidden" % sel)
    else:
        got = (head.inner_text() or "").strip()
        if spec["heading"].lower() not in got.lower():
            res.fail('heading reads "%s", expected "%s"' % (got[:80], spec["heading"]))

    # .pp-anim blocks start at opacity 0 and are revealed by portal.js. Playwright
    # ignores opacity when it decides visibility, so without this a page whose
    # reveal never ran would pass every check above while being invisible.
    anim = page.locator(".pp-anim")
    if anim.count() and page.locator(".pp-anim.is-in").count() == 0:
        res.fail("%d .pp-anim block(s) were never revealed — the page is invisible to a real user" % anim.count())


# ---------------------------------------------------------------- applications

def check_applications(page, res, rows):
    """The screen the reported bug was found on, checked end to end.

    A cached copy of this page lacked #ppAppsEmpty and #ppAppModal while the js
    was current, so the empty notice sat on a populated table and View opened
    nothing — taking the upload UI, which only lives inside that modal, with it.
    """
    if rows == 0:
        res.warn("no applications on this account — the View/upload path was not exercised")
        return

    if page.locator("#ppAppsEmpty").count() == 0:
        res.fail("#ppAppsEmpty is missing from the html — the js cannot hide a notice that is not there")
    elif page.locator("#ppAppsEmpty").first.is_visible():
        res.fail('"no active applications" notice is visible while %d application(s) are listed' % rows)

    headers = [h.strip().lower() for h in page.locator("table thead th").all_inner_texts()]
    if "documents" not in headers:
        res.fail("applications table has no Documents column (got: %s)" % ", ".join(h for h in headers if h))

    # The student name on the row we are about to open — the modal has to show
    # the same one, or it opened the wrong case.
    student = (page.locator("table tbody tr").first.locator("td").first.inner_text() or "").strip()

    view = page.locator("table tbody tr [data-view]").first
    if view.count() == 0:
        res.fail("no View button on the first application row")
        return
    view.click()

    modal = page.locator("#ppAppModal")
    if modal.count() == 0:
        res.fail("#ppAppModal is not in the DOM — View can never open anything")
        return
    try:
        modal.locator(".pp-modal__card").first.wait_for(state="visible", timeout=ACT_TIMEOUT)
    except Exception:
        res.fail("View did not open a visible modal within %dms" % ACT_TIMEOUT)
        return

    # Wait for the rows themselves, not for "something in the box" — the box
    # starts life holding a "Loading the checklist…" line, so anything looser
    # here passes the instant the modal opens and never sees the real content.
    docs = page.locator("#ppAppDocs .pg-app__doc")
    try:
        docs.first.wait_for(state="visible", timeout=ACT_TIMEOUT)
    except Exception:
        said = (page.locator("#ppAppDocs").inner_text() or "").strip().replace("\n", " ")
        res.fail("the document checklist never rendered — #ppAppDocs says: %s" % said[:140])

    body = (modal.locator(".pp-modal__card").first.inner_text() or "")
    if student and student not in body:
        res.fail('modal does not name the student from the row ("%s")' % student[:60])

    doc_rows = docs.count()
    files = page.locator("#ppAppModal input[type='file']").count()
    if doc_rows == 0:
        res.fail("modal opened with no document checklist rows — nothing can be uploaded")
    elif files == 0:
        # every row locked is a legitimate zero; anything else is the upload UI missing
        locked = page.locator("#ppAppDocs .pg-app__doc:has-text('Locked')").count()
        if locked == doc_rows:
            res.warn("no file input: all %d documents are verified and locked" % doc_rows)
        else:
            res.fail("modal has no file input — the partner has no way to upload documents")
    else:
        res.note("modal: %d checklist row(s), %d upload control(s)" % (doc_rows, files))

    page.screenshot(path=os.path.join(SHOTS, "partner-applications--modal.png"), full_page=True)


CHECKS = {"partner-applications.html": check_applications}


# ---------------------------------------------------------------- driver

def run_page(context, spec):
    res = Result(spec["file"])
    page = context.new_page()
    page.set_default_timeout(ACT_TIMEOUT)
    watch(page, res)

    url = BASE + "/" + spec["file"]
    try:
        page.goto(url, wait_until="networkidle", timeout=NAV_TIMEOUT)
    except Exception as exc:
        res.fail("navigation failed: %s" % str(exc).splitlines()[0][:200])
        page.close()
        return res

    assert_rendered(page, spec, res)
    rows = assert_no_contradicting_empty_state(page, res)

    shot = os.path.join(SHOTS, spec["file"].replace(".html", "") + ".png")
    try:
        page.screenshot(path=shot, full_page=True)
    except Exception as exc:
        res.warn("screenshot failed: %s" % str(exc).splitlines()[0][:120])

    check = CHECKS.get(spec["file"])
    if check:
        try:
            check(page, res, rows)
        except Exception as exc:
            res.fail("page-specific check crashed: %s" % str(exc).splitlines()[0][:200])

    page.close()
    return res


def main():
    # Checked before chromium starts, so a missing secret costs no browser launch
    # and reads as a setup problem rather than a failing page.
    if not PASSWORD:
        raise SystemExit(
            "VFI_PARTNER_PASSWORD is not set.\n"
            "  sh:         VFI_PARTNER_PASSWORD='...' python test/ui/smoke_partner.py\n"
            "  PowerShell: $env:VFI_PARTNER_PASSWORD = '...'; python test/ui/smoke_partner.py"
        )

    if not os.path.isdir(SHOTS):
        os.makedirs(SHOTS)

    print("VFI partner console smoke suite")
    print("  base : %s" % BASE)
    print("  user : %s" % EMAIL)
    print("  shots: %s" % SHOTS)
    print("")

    results = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not HEADED)
        # A fresh context every run, so nothing passes on a warm cache — the bug
        # this suite exists for was a caching bug.
        # reduced motion because the stylesheet honours it: without this the
        # .pp-anim entrance fade is still running when the screenshot fires and
        # the evidence shows a half-painted page that is actually fine.
        context = browser.new_context(viewport={"width": 1440, "height": 960},
                                      reduced_motion="reduce",
                                      ignore_https_errors=True)
        try:
            sign_in(context)
            print("signed in as %s\n" % EMAIL)
            for spec in PAGES:
                res = run_page(context, spec)
                results.append(res)
                print("%s %s" % ("PASS" if res.ok else "FAIL", res.name))
                for n in res.notes:
                    print("       . %s" % n)
                for w in res.warnings:
                    print("       ! %s" % w)
                for f in res.failures:
                    print("       x %s" % f)
        finally:
            context.close()
            browser.close()

    bad = [r for r in results if not r.ok]
    print("\n" + "-" * 62)
    print("%d/%d pages passed" % (len(results) - len(bad), len(results)))
    if bad:
        print("FAILED: %s" % ", ".join(r.name for r in bad))
    print("-" * 62)
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
