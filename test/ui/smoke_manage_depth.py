# -*- coding: utf-8 -*-
"""Is the backend actually finished behind every Staff tools menu?

smoke_admin.py already proves the eleven list screens load without a 5xx. This
goes a level deeper, because "the menu is there" is not the question: for each
entry it opens the CREATE form if the resource has one, opens the EDIT form of a
real row if there is one, and reports every file-upload field it finds. A screen
whose list renders and whose form 500s is exactly the kind of half-finished
surface this project keeps finding.

Read-only: it opens forms and reads them. It submits nothing.

Run:
    VFI_ADMIN_EMAIL=... VFI_ADMIN_PASSWORD=... python test/ui/smoke_manage_depth.py
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151")
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
OUT = os.path.dirname(os.path.abspath(__file__)) + "/panel-shots"

if not EMAIL or not PASSWORD:
    print("Set VFI_ADMIN_EMAIL and VFI_ADMIN_PASSWORD.")
    sys.exit(2)

os.makedirs(OUT, exist_ok=True)

# label, list path, does it have a create form?
SCREENS = [
    ("Applications", "manage/staff-applications", False),
    ("Document reviews", "manage/document-reviews", False),
    ("Student lookup", "manage/student-lookup/student-lookups", False),
    ("Partner applications", "manage/partner-applications", False),
    ("Agencies", "manage/agencies", False),
    ("Universities", "manage/universities", True),
    ("University page defaults", "manage/university-defaults", False),
    ("Contact Enquiries", "manage/contact-enquiries", False),
    ("Roles & access", "manage/user-roles", False),
    ("GDPR requests", "manage/data-subject-requests", False),
    ("Disclosures", "manage/disclosures", False),
]

ok = fail = 0
rows = []


def check(good, label, extra=""):
    global ok, fail
    if good:
        ok += 1
    else:
        fail += 1
    print(f"  {'PASS' if good else 'FAIL'}  {label} {extra}".rstrip())
    return good


def page_broken(page):
    """Filament shows its own error page; Laravel shows a stack trace."""
    body = page.inner_text("body")[:4000]
    for marker in ("Server Error", "Whoops", "Internal Server Error",
                   "SQLSTATE", "Undefined ", "Call to a member function",
                   "419", "Page Expired"):
        if marker in body:
            return marker
    return None


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1600, "height": 1000})
    page = ctx.new_page()
    status = {}
    page.on("response", lambda r: status.setdefault(r.url, r.status)
            if r.status >= 500 else None)

    page.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
    page.locator("input[type=email]").first.fill(EMAIL)
    page.locator("input[type=password]").first.fill(PASSWORD)
    page.locator("#stepPassword button, form button").first.click()
    page.wait_for_url("**/admin-panel/**", timeout=30000)
    print("signed in\n")

    for label, path, has_create in SCREENS:
        print(f"=== {label} ===")
        page.goto(f"{BASE}/{path}", wait_until="networkidle", timeout=60000)
        page.wait_for_timeout(1800)

        broken = page_broken(page)
        check(broken is None, f"{label}: the list screen renders", broken or "")

        uploads = page.locator('input[type=file]').count()
        actions = page.locator(".fi-ta-actions button, .fi-ac button").count()
        row_count = page.locator("table tbody tr").count()
        toolbar = [
            (b.text_content() or "").strip()
            for b in page.locator(".fi-header button, .fi-header a").element_handles()
        ]
        toolbar = [t for t in toolbar if t][:4]

        # CREATE form
        created = "-"
        if has_create:
            page.goto(f"{BASE}/{path}/create", wait_until="networkidle", timeout=60000)
            page.wait_for_timeout(2200)
            broken = page_broken(page)
            check(broken is None, f"{label}: the create form opens", broken or "")
            created = "opens" if broken is None else "BROKEN"
            uploads = max(uploads, page.locator('input[type=file]').count())

        # EDIT form of a real row
        edited = "-"
        page.goto(f"{BASE}/{path}", wait_until="networkidle", timeout=60000)
        page.wait_for_timeout(1500)
        link = page.locator(f'a[href*="/{path.split("/")[-1]}/"][href$="/edit"]').first
        if link.count():
            page.goto(link.get_attribute("href"), wait_until="networkidle", timeout=60000)
            page.wait_for_timeout(2500)
            broken = page_broken(page)
            check(broken is None, f"{label}: an existing record opens for editing", broken or "")
            edited = "opens" if broken is None else "BROKEN"
            fields = page.locator("input, textarea, select").count()
            uploads = max(uploads, page.locator('input[type=file]').count())
            print(f"        {fields} inputs on the edit form")
        else:
            edited = "no edit page"

        # Two of these are custom Filament Pages rather than resources, so they
        # have no table at all - the honest measure there is whether the form
        # rendered any fields.
        inputs_here = page.locator("input, textarea, select").count()

        rows.append((label, row_count or f"({inputs_here} fields)",
                     len(toolbar), actions, uploads, created, edited))
        print(f"        rows={row_count} toolbar={toolbar} row-actions={actions} uploads={uploads}\n")

    fives = {u: s for u, s in status.items() if s and s >= 500}
    check(not fives, "no 5xx from any screen or form", str(list(fives)[:2]))

    print("\n" + "=" * 96)
    print("%-26s %6s %8s %8s %8s %-10s %-14s" % (
        "SCREEN", "ROWS", "TOOLBAR", "ACTIONS", "UPLOADS", "CREATE", "EDIT"))
    print("-" * 96)
    for r in rows:
        print("%-26s %6s %8s %8s %8s %-10s %-14s" % r)
    print("=" * 96)
    print(f"\n{ok} passed, {fail} failed")
    ctx.close()
    browser.close()

sys.exit(1 if fail else 0)
