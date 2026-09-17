# -*- coding: utf-8 -*-
"""Site settings and page visibility, pressed like a person presses them.

These two screens replace editors that wrote to the editor's own localStorage,
so the thing worth proving is not that a form renders - it is that a change
SURVIVES. Both checks save, reload the page from scratch, and read the value
back out of the input.

IT WRITES TO THE LIVE SITE. Site settings appear in the footer of every page,
so this restores the original value at the end, including on failure, and the
value it writes is a marked variant of whatever was already there rather than
something invented. Page visibility is toggled off and straight back on for a
single page.

Run:
    VFI_ADMIN_EMAIL=... VFI_ADMIN_PASSWORD=... python test/ui/smoke_admin_settings.py
"""
import os
import sys
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151")
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
OUT = os.path.dirname(os.path.abspath(__file__)) + "/panel-shots"

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
    if locator.count() == 0:
        return False
    box = locator.first.bounding_box()
    if not box or box["width"] < 20 or box["height"] < 20:
        return False
    size = locator.page.viewport_size
    return 0 <= box["x"] < size["width"] and box["y"] < size["height"]


def field(page, label):
    """The input whose Vuetify label is exactly `label`."""
    return page.locator(
        f'.v-input:has(label:text-is("{label}")) input, '
        f'.v-input:has(label:text-is("{label}")) textarea'
    ).first


def wait_for(fn, timeout=20000):
    deadline = time.time() + timeout / 1000
    while time.time() < deadline:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.3)
    return False


original_tagline = None

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1500, "height": 950})
    page = ctx.new_page()
    errors = []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)

    try:
        print("=== 1. sign in ===")
        page.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
        page.locator("input[type=email]").first.fill(EMAIL)
        page.locator("input[type=password]").first.fill(PASSWORD)
        page.locator("#stepPassword button, form button").first.click()
        page.wait_for_url("**/admin-panel/**", timeout=30000)
        page.wait_for_timeout(2500)
        if not check("/admin-panel/" in page.url, "login lands on the console", page.url):
            raise SystemExit(1)

        # ------------------------------------------------ 2. site settings
        print("\n=== 2. site settings, from the sidebar ===")
        nav = page.locator('a[href$="/settings"]').first
        check(on_screen(nav), "the sidebar shows Site settings")
        nav.click()
        page.wait_for_url("**/settings**", timeout=20000)
        page.wait_for_timeout(3000)

        body = page.inner_text("body")
        check("role does not include" not in body, "we are allowed in")
        check("Could not load" not in body, "the settings loaded")

        # The client's requirement in one assertion: open a thing and SEE what
        # is stored in it.
        brand = field(page, "Site name")
        tagline = field(page, "Tagline")
        phone = field(page, "Phone")
        check(brand.input_value().strip() != "", "the stored site name is in the input", brand.input_value())
        check(phone.input_value().strip() != "", "the stored phone number is in the input", phone.input_value())
        # inner_text on a multi-match locator returns only the first element,
        # so this reads the whole page: the sections are separate cards.
        shown = page.inner_text("body")
        missing = [t for t in ("Brand", "Contact", "Social links") if t not in shown]
        check(not missing, "every section the schema declares is on screen", str(missing))
        page.screenshot(path=f"{OUT}/40-settings.png", full_page=True)

        print("\n=== 3. change the tagline and prove it survives a reload ===")
        original_tagline = tagline.input_value()
        probe = (original_tagline or "overseas education") + " [check]"
        tagline.fill(probe)

        save = page.locator('button:has-text("Save changes")').first
        check(save.is_enabled(), "Save wakes up once something is edited")
        save.click()
        check(
            wait_for(lambda: "Saved." in page.inner_text("body")),
            "it says it saved",
        )

        # A FULL reload, not a re-render: this is the difference between the old
        # screen and this one. localStorage would have survived a reload in this
        # same browser, so the value is also read back through a fresh context
        # below.
        page.reload(wait_until="networkidle")
        page.wait_for_timeout(3000)
        check(
            field(page, "Tagline").input_value() == probe,
            "the change is still there after a reload",
            field(page, "Tagline").input_value(),
        )

        print("\n=== 4. and in a browser that has never seen this site ===")
        # The real test of "saved on the server". A second, clean context shares
        # no localStorage and no IndexedDB with the first.
        ctx2 = browser.new_context(viewport={"width": 1400, "height": 900})
        page2 = ctx2.new_page()
        page2.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
        page2.locator("input[type=email]").first.fill(EMAIL)
        page2.locator("input[type=password]").first.fill(PASSWORD)
        page2.locator("#stepPassword button, form button").first.click()
        page2.wait_for_url("**/admin-panel/**", timeout=30000)
        page2.goto(f"{BASE}/admin-panel/settings/", wait_until="networkidle")
        page2.wait_for_timeout(3000)
        check(
            field(page2, "Tagline").input_value() == probe,
            "a different browser sees it too, so it really is on the server",
            field(page2, "Tagline").input_value(),
        )
        ctx2.close()

        print("\n=== 5. put the tagline back ===")
        page.reload(wait_until="networkidle")
        page.wait_for_timeout(3000)
        field(page, "Tagline").fill(original_tagline)
        page.locator('button:has-text("Save changes")').first.click()
        check(
            wait_for(lambda: "Saved." in page.inner_text("body")),
            "restored the original wording",
        )
        original_tagline = None  # restored; the finally block has nothing to do

        # ------------------------------------------------ 6. page visibility
        print("\n=== 6. pages on and off ===")
        nav = page.locator('a[href$="/pages"]').first
        check(on_screen(nav), "the sidebar shows Pages")
        nav.click()
        page.wait_for_url("**/pages**", timeout=20000)
        page.wait_for_timeout(3000)

        body = page.inner_text("body")
        check("Only the account owner" not in body, "the owner is allowed in")
        check(
            "does not block the address" in body,
            "the screen says what off actually means",
        )

        switches = page.locator(".v-switch")
        check(switches.count() > 20, "the whole page catalogue is listed", str(switches.count()))
        page.screenshot(path=f"{OUT}/41-pages.png", full_page=True)

        # csr.html: real, unlocked, and the quietest page to unlink for
        # the few seconds this takes.
        row = page.locator('.v-list-item:has(code:text-is("csr.html"))').first
        if not check(row.count() > 0, "found a page to toggle"):
            raise SystemExit(1)

        sw = row.locator("input[type=checkbox]").first
        was_on = sw.is_checked()
        check(was_on, "csr.html starts switched on")

        sw.click()
        check(
            wait_for(lambda: "no longer linked" in page.inner_text("body")),
            "switching it off is confirmed in words",
        )

        page.reload(wait_until="networkidle")
        page.wait_for_timeout(3000)
        sw = page.locator('.v-list-item:has(code:text-is("csr.html"))').first.locator(
            "input[type=checkbox]"
        ).first
        check(not sw.is_checked(), "and it is still off after a reload")

        sw.click()
        check(
            wait_for(lambda: "linked from the site again" in page.inner_text("body")),
            "switching it back on is confirmed too",
        )
        page.reload(wait_until="networkidle")
        page.wait_for_timeout(3000)
        sw = page.locator('.v-list-item:has(code:text-is("csr.html"))').first.locator(
            "input[type=checkbox]"
        ).first
        check(sw.is_checked(), "csr.html is back on, as it started")

        print("\n=== 7. a locked page refuses, and says so ===")
        locked = page.locator('.v-list-item:has-text("always on")').first
        if locked.count():
            lsw = locked.locator("input[type=checkbox]").first
            check(not lsw.is_enabled(), "a locked page's switch is not even offered")
        else:
            check(True, "no locked pages in the catalogue to check")

        real = [e for e in errors if "401" not in e]
        check(not real, "no console errors", str(real[:2]))

    finally:
        # Never leave the live site with a test tagline in its footer.
        if original_tagline is not None:
            print("\n!! restoring the tagline after a failure")
            try:
                page.goto(f"{BASE}/admin-panel/settings/", wait_until="networkidle")
                page.wait_for_timeout(3000)
                field(page, "Tagline").fill(original_tagline)
                page.locator('button:has-text("Save changes")').first.click()
                page.wait_for_timeout(2500)
                print("   restored:", original_tagline)
            except Exception as e:
                print("   COULD NOT RESTORE — set the tagline back by hand:", original_tagline, e)

        print(f"\n{'-' * 60}\n{ok} passed, {fail} failed\n{'-' * 60}")
        ctx.close()
        browser.close()

sys.exit(1 if fail else 0)
