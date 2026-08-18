#!/usr/bin/env python3
"""Real-browser smoke gate for the VFI staff back-office (/manage).

Why this exists: the staff application queue rendered ZERO rows on production
for days while every PHPUnit test passed. The console tables carry Postgres RLS
FORCE and staff hold no tenant, so the panel needs a read bypass on BOTH the
Filament stack (page render) and the web group (/livewire/update, where buttons
act). Tests run SQLite, which has no row-level security, so the flag is a no-op
and a rendering test passes whether the wiring is right or not. Only a browser
against real Postgres can see it.

Run:
    export VFI_ADMIN_EMAIL=... VFI_ADMIN_PASSWORD=...
    python test/ui/smoke_admin.py            # against VFI_BASE or live
Exits non-zero on failure so it can gate a deploy.
"""
import json
import os
import sys
from urllib.parse import unquote

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151").rstrip("/")
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
SHOTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "shots")

# `.fi-modal` is always present but permanently hidden — it wraps the window.
# A comma selector with state="visible" resolves to it and never settles, which
# once made every action look broken when all four were fine.
MODAL = ".fi-modal-window"

PAGES = [
    "manage", "manage/staff-applications", "manage/partner-applications",
    "manage/document-reviews", "manage/agencies", "manage/user-roles",
    "manage/contact-enquiries", "manage/data-subject-requests", "manage/disclosures",
    "manage/student-lookup/student-lookups", "manage/universities",
    "manage/university-defaults",
    "manage/content/blogs", "manage/content/events", "manage/content/news-items",
    "manage/content/photos", "manage/content/pp-docs", "manage/content/pp-emails",
    "manage/content/pp-managers", "manage/content/pp-notifs",
    "manage/content/pp-quicklinks", "manage/content/pp-updates",
]

# Row actions on the staff queue, with a word that must appear in the dialog.
# A modal that opens empty is not a working button.
ACTIONS = [
    ("Documents", ("Passport", "passport", "Missing", "Verified", "Academic")),
    ("Move", ("status", "Status", "Review", "Offer")),
    ("Add note", ("note", "Note")),
    ("Notes", ()),
]

passed = failed = 0


def check(good, label, extra=""):
    global passed, failed
    if good:
        passed += 1
        print(f"  PASS  {label} {extra}".rstrip())
    else:
        failed += 1
        print(f"  FAIL  {label} {extra}".rstrip())
    return good


def main():
    if not EMAIL or not PASSWORD:
        sys.exit("Set VFI_ADMIN_EMAIL and VFI_ADMIN_PASSWORD. They are never stored "
                 "in this repo, which is public.")

    os.makedirs(SHOTS, exist_ok=True)
    print(f"VFI staff back-office smoke suite\n  base : {BASE}\n  user : {EMAIL}\n")

    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        ctx = browser.new_context(viewport={"width": 1600, "height": 1000})

        # Sign in over the API on the browser context so the session cookie is real.
        ctx.request.get(f"{BASE}/sanctum/csrf-cookie")
        token = unquote([c["value"] for c in ctx.cookies() if c["name"] == "XSRF-TOKEN"][0])
        res = ctx.request.post(
            f"{BASE}/api/admin/login",
            headers={"Content-Type": "application/json", "Accept": "application/json",
                     "X-XSRF-TOKEN": token},
            data=json.dumps({"email": EMAIL, "password": PASSWORD}),
        )
        if not check('"step":"done"' in res.text(), "sign in", res.text()[:120]):
            browser.close()
            return 1

        page = ctx.new_page()
        errors = []
        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        page.on("response",
                lambda r: errors.append(f"HTTP {r.status} {r.url[-60:]}") if r.status >= 500 else None)

        print("\n-- every admin page renders --")
        for path in PAGES:
            before = len(errors)
            try:
                resp = page.goto(f"{BASE}/{path}", wait_until="domcontentloaded", timeout=45000)
                page.wait_for_timeout(1500)
                body = page.inner_text("body")
                broke = (resp and resp.status >= 400) or any(
                    s in body for s in ("Server Error", "Whoops", "SQLSTATE")
                ) or errors[before:]
                check(not broke, path, "" if not broke else str(errors[before:][:1] or resp.status))
            except Exception as exc:  # a page that will not load at all
                check(False, path, str(exc)[:70])

        print("\n-- the staff queue is not empty --")
        page.goto(f"{BASE}/manage/staff-applications", wait_until="domcontentloaded", timeout=45000)
        page.wait_for_timeout(3000)
        rows = page.query_selector_all(".fi-ta-row")
        body = page.inner_text("body")
        # The whole point: RLS makes this render zero rows when the bypass is
        # missing from either middleware stack, with no error anywhere.
        check(len(rows) > 0, "queue renders applications", f"({len(rows)} rows)")
        check("No applications" not in body, "no empty-state above a populated queue")
        check("Documents" in body, "readiness column present")
        page.screenshot(path=os.path.join(SHOTS, "manage-queue.png"))

        print("\n-- every row action opens a real dialog --")
        for label, expect in ACTIONS:
            dismiss(page)
            buttons = [b for b in page.query_selector_all("button")
                       if (b.inner_text() or "").strip() == label and b.is_visible()]
            if not check(bool(buttons), f"'{label}' button present"):
                continue
            try:
                buttons[0].click(timeout=8000)
                page.wait_for_selector(MODAL, state="visible", timeout=9000)
            except Exception as exc:
                check(False, f"'{label}' opens a dialog", str(exc)[:60])
                continue
            text = page.inner_text(MODAL)
            check(True, f"'{label}' opens a dialog", f"({len(text)} chars)")
            if expect:
                check(any(k in text for k in expect), f"'{label}' dialog has content")
            page.screenshot(path=os.path.join(SHOTS, f"manage-{label.replace(' ', '-')}.png"))

        dismiss(page)
        fatal = [e for e in errors if e.startswith("HTTP 5")]
        check(not fatal, "no 5xx anywhere in the panel", str(fatal[:2]))

        print("\n" + "-" * 62)
        print(f"{passed} passed, {failed} failed")
        print("-" * 62)
        browser.close()

    return 1 if failed else 0


def dismiss(page):
    """Filament leaves a click-swallowing container behind, so the next action is
    unreachable until the current dialog is really gone."""
    for _ in range(6):
        page.keyboard.press("Escape")
        page.wait_for_timeout(600)
        if not [m for m in page.query_selector_all(MODAL) if m.is_visible()]:
            return True
    return False


if __name__ == "__main__":
    sys.exit(main())
