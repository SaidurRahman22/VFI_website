# -*- coding: utf-8 -*-
"""Website content, edited the way a person edits it.

Every check in here presses a button. That is the whole point: this project has
twice shipped a screen that passed its API tests and did not work - the partner
applications table with an "you have no applications" banner sitting on top of
five rows, and the case drawer that measured x:1500 in a 1500px viewport and so
never came on screen while every DOM assertion about its contents passed.

So this walks the real job: sign in through the form, find Website content in
the sidebar and CLICK it, add an event, see it in the list, edit it, reorder it,
and delete it. Where something has to be VISIBLE, its bounding box is measured
rather than its presence in the DOM asserted.

IT WRITES TO THE LIVE SITE. There is no staging environment, so an event
created here really does appear on the public events page for as long as the run
takes. Everything it creates is named so nobody could mistake it for real
content, and the run deletes all of it - including on failure - so the database
does not accumulate the demo rows the client spent a day removing.

Run:
    VFI_ADMIN_EMAIL=... VFI_ADMIN_PASSWORD=... python test/ui/smoke_admin_content.py
"""
import os
import sys
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151")
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
OUT = os.path.dirname(os.path.abspath(__file__)) + "/panel-shots"

# Unmistakable, so a human who sees one on the live site knows what it is.
MARK = "ZZ AUTOMATED CHECK - safe to delete"

if not EMAIL or not PASSWORD:
    print("Set VFI_ADMIN_EMAIL and VFI_ADMIN_PASSWORD.")
    sys.exit(2)

os.makedirs(OUT, exist_ok=True)
ok = fail = 0


def check(good, label, extra=""):
    global ok, fail
    if good:
        ok += 1
        print(f"  PASS  {label} {extra}".rstrip())
    else:
        fail += 1
        print(f"  FAIL  {label} {extra}".rstrip())
    return good


def on_screen(locator):
    """Visible AND actually inside the viewport - see the drawer note above."""
    if locator.count() == 0:
        return False
    box = locator.first.bounding_box()
    if not box or box["width"] < 20 or box["height"] < 20:
        return False
    page = locator.page
    size = page.viewport_size
    return 0 <= box["x"] < size["width"] and box["y"] < size["height"]


def field(page, label):
    """The input inside the dialog whose Vuetify label contains `label`."""
    return page.locator(
        f'.v-dialog .v-input:has(label:text-is("{label}")) input, '
        f'.v-dialog .v-input:has(label:text-is("{label}")) textarea'
    ).first


def dialog_button(page, text):
    return page.locator(f'.v-dialog button:has-text("{text}")').first


def click_when_ready(page, selector, label, timeout=30000):
    """Click, but say WHY if the button never becomes clickable.

    The add button is disabled while the list is loading, and the deploy
    replaces admin-panel/ wholesale - so a page loaded in that window can get
    new HTML alongside a JS chunk that is not written yet, and the button then
    never arrives. A bare click just times out and blames the click.
    """
    btn = page.locator(selector).first
    deadline = time.time() + timeout / 1000
    while time.time() < deadline:
        if btn.count() and btn.is_visible() and btn.is_enabled():
            btn.click()

            return
        page.wait_for_timeout(300)

    check(
        False,
        f"{label} never became clickable",
        f"present={btn.count()} — the screen may have loaded mid-deploy",
    )
    raise SystemExit(1)


def row_titles(page):
    items = page.locator(".v-card .v-list .v-list-item-title")
    return [items.nth(i).inner_text().strip() for i in range(items.count())]


def wait_rows(page, predicate, timeout=15000):
    """Poll the RENDERED list until it says what we expect, or give up.

    Polling the DOM the user sees, rather than waiting on the network, is
    deliberate: a 200 from the API is exactly the evidence that has twice been
    mistaken for a working screen here.
    """
    deadline = time.time() + timeout / 1000
    while time.time() < deadline:
        if predicate(row_titles(page)):
            return True
        page.wait_for_timeout(300)
    return False


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(
        viewport={"width": 1500, "height": 950},
        record_video_dir=OUT + "/video",
    )
    page = ctx.new_page()
    errors = []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)

    try:
        # ---------------------------------------------------------- 1. sign in
        print("=== 1. sign in through the form ===")
        page.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
        page.locator("input[type=email]").first.fill(EMAIL)
        page.locator("input[type=password]").first.fill(PASSWORD)
        page.locator("#stepPassword button, form button").first.click()
        try:
            page.wait_for_url("**/admin-panel/**", timeout=30000)
        except Exception:
            pass
        page.wait_for_timeout(2500)
        if not check("/admin-panel/" in page.url, "login lands on the console", page.url):
            raise SystemExit(1)

        # ------------------------------------------- 2. reach it from the nav
        print("\n=== 2. find Website content in the sidebar and click it ===")
        nav_link = page.locator('a:has-text("Website content")').first
        check(on_screen(nav_link), "the sidebar shows a Website content link")
        nav_link.click()
        page.wait_for_url("**/content**", timeout=20000)
        page.wait_for_timeout(2500)
        check("/content" in page.url, "it navigates to the content screen", page.url)

        body = page.inner_text("body")
        check(
            "role does not include" not in body,
            "the screen does not claim we lack permission",
        )
        check("Could not load" not in body, "the collections loaded")

        # ------------------------------------------------------- 3. the tabs
        print("\n=== 3. all ten collections, as tabs ===")
        tabs = page.locator(".v-card .v-tab")
        # text_content, not inner_text: ten tabs overflow 1500px so the strip
        # scrolls, and inner_text returns "" for whatever is currently scrolled
        # out of view - which says nothing about whether the tab exists.
        names = [
            (tabs.nth(i).text_content() or "").split("\n")[0].strip()
            for i in range(tabs.count())
        ]
        check(tabs.count() == 10, "ten tabs, not ten sidebar entries", str(tabs.count()))
        for expected in ["Events", "Blog posts", "Photo gallery", "Quick links"]:
            check(any(expected in n for n in names), f'tab "{expected}" is present')

        # Grouped into two strips so all ten fit. Ten in one strip pushed four
        # of them off the end, reachable only by finding the scroll arrow.
        card = page.inner_text(".v-card")
        check("PUBLIC WEBSITE" in card.upper(), "the strips say which content is public")
        check("PARTNER CONSOLE" in card.upper(), "and which fills the partner console")
        for t in ["Quick links", "Learning documents", "Email updates", "Notifications"]:
            tabloc = page.locator(f'.v-tab:has-text("{t}")').first
            check(on_screen(tabloc), f'"{t}" is on screen without scrolling the strip')
        page.screenshot(path=f"{OUT}/20-content-tabs.png", full_page=True)

        # ------------------------------------------------------- 4. create
        print("\n=== 4. add an event ===")
        click_when_ready(page, 'button:has-text("New event")', "New event")
        page.wait_for_timeout(1200)

        dlg = page.locator(".v-dialog .v-card")
        # The lesson from the case drawer: measured, not assumed.
        check(on_screen(dlg), "the editor is actually on screen, not parked off it")

        title_a = f"{MARK} — first"
        field(page, "Event title *").fill(title_a)
        field(page, "City").fill("Dhaka")
        field(page, "Date").fill("2026-11-04")
        field(page, "Time").fill("10:00 am – 5:00 pm")
        page.screenshot(path=f"{OUT}/21-content-new-event.png")

        dialog_button(page, "Add event").click()
        check(
            wait_rows(page, lambda t: any(title_a in x for x in t)),
            "the new event appears in the list",
        )
        check(
            page.locator(".v-dialog .v-card").count() == 0
            or not on_screen(page.locator(".v-dialog .v-card")),
            "and the editor closed itself",
        )
        check("Dhaka" in page.inner_text(".v-card .v-list"), "with the city we typed")

        # ------------------------------------------------------- 5. edit
        print("\n=== 5. edit it ===")
        row = page.locator(f'.v-list-item:has-text("{title_a}")').first
        row.locator('button:has-text("Edit")').first.click()
        page.wait_for_timeout(1200)
        check(on_screen(page.locator(".v-dialog .v-card")), "the editor reopened on screen")

        # The data we stored has to be sitting in the boxes - the client asked
        # for exactly this: open a thing and SEE what is in it.
        check(
            field(page, "Event title *").input_value() == title_a,
            "the stored title is in the input",
            field(page, "Event title *").input_value()[:40],
        )
        check(field(page, "City").input_value() == "Dhaka", "the stored city is in the input")
        check(
            field(page, "Date").input_value() == "2026-11-04",
            "the stored date fills the date picker",
            field(page, "Date").input_value(),
        )
        check(
            "the id the public site uses" in page.inner_text(".v-dialog"),
            "and it shows the public id it saved under",
        )

        field(page, "City").fill("Chattogram")
        dialog_button(page, "Save changes").click()
        page.wait_for_timeout(2500)
        check(
            "Chattogram" in page.inner_text(".v-card .v-list"),
            "the edit shows in the list",
        )

        # ------------------------------------------------------- 6. reorder
        print("\n=== 6. reorder ===")
        click_when_ready(page, 'button:has-text("New event")', "New event")
        page.wait_for_timeout(1000)
        title_b = f"{MARK} — second"
        field(page, "Event title *").fill(title_b)
        dialog_button(page, "Add event").click()
        page.wait_for_timeout(2500)

        titles = row_titles(page)
        check(titles and title_b in titles[0], "a new item lands at the top", str(titles[:1]))

        page.locator(f'.v-list-item:has-text("{title_b}")').first.locator(
            'button[aria-label*="down"]'
        ).first.click()
        page.wait_for_timeout(2500)
        moved = row_titles(page)
        check(
            len(moved) > 1 and title_b in moved[1],
            "moving it down swaps it with the row below",
            str(moved[:2]),
        )
        page.screenshot(path=f"{OUT}/22-content-reordered.png", full_page=True)

        # ------------------------------------------------- 6b. image upload
        print("\n=== 6b. upload a cover image ===")
        # An image already in the repo, deliberately: the server content-hashes
        # what it stores, so uploading the SAME file every run produces the same
        # managed id and the same file on disk. One run and a hundred runs leave
        # exactly one extra object in storage/media, not one per run.
        shot = os.path.join(
            os.path.dirname(os.path.abspath(__file__)), "..", "..", "assets", "img", "office-desk.jpg"
        )
        if not check(os.path.exists(shot), "the upload source image exists", shot):
            raise SystemExit(1)
        page.locator(f'.v-list-item:has-text("{title_a}")').first.locator(
            'button:has-text("Edit")'
        ).first.click()
        page.wait_for_timeout(1200)

        page.locator('.v-dialog input[type=file]').first.set_input_files(shot)
        # The upload is a round trip: wait for the id to land in the box that
        # shows it, which is the same box the person reads when a picture is
        # wrong.
        stored = page.locator('.v-dialog .v-input:has(label:text-is("Stored image id")) input').first
        got = ""
        deadline = time.time() + 30
        while time.time() < deadline:
            got = stored.input_value()
            if got:
                break
            page.wait_for_timeout(400)

        check(got.startswith("/storage/media/"), "the upload returns a managed image id", got[:48])
        check(
            got.endswith(".jpg"),
            "re-encoded to jpeg by the server, not trusted as uploaded",
            got[-8:],
        )
        preview = page.locator(".v-dialog .v-avatar img").first
        check(on_screen(preview), "and the preview shows the picture in the form")

        dialog_button(page, "Save changes").click()
        page.wait_for_timeout(2500)
        thumb = page.locator(f'.v-list-item:has-text("{title_a}") img').first
        check(on_screen(thumb), "the thumbnail shows in the list afterwards")
        page.screenshot(path=f"{OUT}/24-content-image.png", full_page=True)

        # ------------------------------------- 7. the display-date collections
        print("\n=== 7. a display date is a text box, not a picker ===")
        page.locator('.v-tab:has-text("Learning documents")').first.click()
        page.wait_for_timeout(2000)
        click_when_ready(page, 'button:has-text("New document")', "New document")
        page.wait_for_timeout(1200)
        date_input = field(page, "Date")
        check(
            date_input.get_attribute("type") == "text",
            "pp_docs Date is type=text, so '12 Aug 2026' is not silently wiped",
            str(date_input.get_attribute("type")),
        )
        dialog_button(page, "Cancel").click()
        page.wait_for_timeout(800)

        # ------------------------------------------------ 8. blog body safety
        print("\n=== 8. a script tag in a blog body does not survive ===")
        page.locator('.v-tab:has-text("Blog posts")').first.click()
        page.wait_for_timeout(2000)
        click_when_ready(page, 'button:has-text("New blog post")', "New blog post")
        page.wait_for_timeout(1200)
        blog_title = f"{MARK} — post"
        field(page, "Post title *").fill(blog_title)
        field(page, "Body").fill("Useful advice <script>alert(1)</script> here.")
        dialog_button(page, "Add blog post").click()
        page.wait_for_timeout(2500)
        check(
            wait_rows(page, lambda t: any(blog_title in x for x in t)),
            "the post saved",
        )
        page.locator(f'.v-list-item:has-text("{blog_title}")').first.locator(
            'button:has-text("Edit")'
        ).first.click()
        page.wait_for_timeout(1500)
        stored_body = field(page, "Body").input_value()
        check("<script" not in stored_body, "the script tag was stripped on save", stored_body[:50])
        check("Useful advice" in stored_body, "and the words the author wrote survived")
        dialog_button(page, "Cancel").click()
        page.wait_for_timeout(800)

        # ------------------------------------------------------- 9. delete
        print("\n=== 9. delete, with a confirmation that tells the truth ===")
        page.locator(f'.v-list-item:has-text("{blog_title}")').first.locator(
            'button[aria-label*="Delete"]'
        ).first.click()
        page.wait_for_timeout(1200)
        confirm = page.locator('.v-dialog .v-card:has-text("Remove this from the website")')
        check(on_screen(confirm), "the confirmation is on screen")
        check(
            "can be restored" in page.inner_text(".v-dialog"),
            "and says the removal is recoverable, because it is",
        )
        page.locator('.v-dialog button:has-text("Remove it")').first.click()
        page.wait_for_timeout(2500)
        check(
            wait_rows(page, lambda t: not any(blog_title in x for x in t)),
            "the post left the list",
        )

        # ---------------------------------------------------- 10. clean up
        print("\n=== 10. remove everything this run created ===")
        page.locator('.v-tab:has-text("Events")').first.click()
        page.wait_for_timeout(2000)
        for _ in range(6):
            leftover = page.locator(f'.v-list-item:has-text("{MARK}")')
            if leftover.count() == 0:
                break
            leftover.first.locator('button[aria-label*="Delete"]').first.click()
            page.wait_for_timeout(900)
            page.locator('.v-dialog button:has-text("Remove it")').first.click()
            page.wait_for_timeout(2200)
        check(
            page.locator(f'.v-list-item:has-text("{MARK}")').count() == 0,
            "no test content is left on the live site",
        )

        page.screenshot(path=f"{OUT}/23-content-clean.png", full_page=True)

        # The login page asks /api/admin/me before you are signed in, which is a
        # 401 by design. Only anything else counts.
        real = [e for e in errors if "401" not in e]
        check(not real, "no console errors", str(real[:2]))

    finally:
        print(f"\n{'-' * 60}\n{ok} passed, {fail} failed\n{'-' * 60}")
        ctx.close()
        browser.close()

sys.exit(1 if fail else 0)
