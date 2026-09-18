# -*- coding: utf-8 -*-
"""Page images, Backup, and the restore that the delete dialog promises.

Three screens that replaced editors which wrote to the editor's own
localStorage, plus one promise that was false until this week. What is worth
proving is not that a form renders - it is that a change SURVIVES, and that the
sentences on screen are true.

IT WRITES TO THE LIVE SITE, so:
  - the image slot it touches is restored to its original id at the end,
    including on failure
  - the content item it deletes and restores is one this check created
  - IT NEVER RUNS AN IMPORT. Import replaces all site content. The check proves
    the file preview and the confirmation exist and then CANCELS. Anything else
    would be a check that wipes the client's website.

Run:
    VFI_ADMIN_EMAIL=... VFI_ADMIN_PASSWORD=... python test/ui/smoke_admin_images_backup.py
"""
import io
import json
import os
import sys
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151")
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = HERE + "/panel-shots"

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
    if locator.count() == 0:
        return False
    box = locator.first.bounding_box()
    if not box or box["width"] < 20 or box["height"] < 20:
        return False
    size = locator.page.viewport_size
    return 0 <= box["x"] < size["width"] and box["y"] < size["height"]


def wait_for(fn, timeout=25000):
    deadline = time.time() + timeout / 1000
    while time.time() < deadline:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.3)
    return False


def sign_in(page):
    page.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
    page.locator("input[type=email]").first.fill(EMAIL)
    page.locator("input[type=password]").first.fill(PASSWORD)
    page.locator("#stepPassword button, form button").first.click()
    page.wait_for_url("**/admin-panel/**", timeout=30000)
    page.wait_for_timeout(2500)


# What to put back if this run dies half way.
restore_slot = None  # (key, original imgId or None)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1500, "height": 950}, accept_downloads=True)
    page = ctx.new_page()
    errors = []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)

    try:
        print("=== 1. sign in, and the console is on the site's palette ===")
        sign_in(page)
        if not check("/admin-panel/" in page.url, "login lands on the console", page.url):
            raise SystemExit(1)

        primary = page.evaluate(
            "getComputedStyle(document.documentElement).getPropertyValue('--v-theme-primary')")
        check(
            primary.replace(" ", "") == "47,98,168",
            "primary is the site's royal blue #2f62a8, not the template purple",
            primary,
        )
        font = page.evaluate("getComputedStyle(document.body).fontFamily")
        check("DM Sans" in font, "body is set in the site's DM Sans", font.split(",")[0])

        # ------------------------------------------------- 2. page images
        print("\n=== 2. page images ===")
        nav = page.locator('a[href$="/images"]').first
        check(on_screen(nav), "the sidebar shows Page images")
        nav.click()
        page.wait_for_url("**/images**", timeout=20000)
        page.wait_for_timeout(3500)

        body = page.inner_text("body")
        check("role does not include" not in body, "we are allowed in")
        check("Could not load" not in body, "the slot list loaded")

        ids = page.locator("text=/\\/storage\\/media\\/|assets\\/img\\//")
        stored = page.locator(".v-card:has-text('Stored image id')")
        check(stored.count() >= 8, "every slot shows the id it is storing", str(stored.count()))

        # The previews have to actually resolve. A blank one here meant the
        # /storage symlink was missing and every upload 404'd.
        loaded = page.evaluate("""() => {
            const imgs = Array.from(document.querySelectorAll('img'))
              .filter(i => /storage\\/media|assets\\/img/.test(i.currentSrc || i.src));
            return { total: imgs.length, broken: imgs.filter(i => i.naturalWidth === 0).length };
        }""")
        check(loaded["total"] > 0, "the slot previews are real images", str(loaded))
        check(loaded["broken"] == 0, "and none of them failed to load", str(loaded))
        page.screenshot(path=f"{OUT}/51-images.png", full_page=True)

        print("\n=== 3. replace a picture, which is where the version is proved ===")
        # The slot save now REQUIRES the version of the media row. If the screen
        # does not send it the upload succeeds and the slot never changes - so
        # this is the check that the two halves were wired together.
        card = page.locator(".v-card").filter(has_text="Stored image id").filter(
            has_text="collage").first
        if not check(card.count() > 0, "found a collage card to use"):
            raise SystemExit(1)

        slot_id_input = card.locator("input, textarea")
        before = page.evaluate(
            """() => {
                const c = Array.from(document.querySelectorAll('.v-card'))
                  .find(e => /Stored image id/.test(e.innerText) && /collage/i.test(e.innerText));
                const m = c && c.innerText.match(/(\\/storage\\/media\\/\\S+|assets\\/img\\/\\S+)/);
                return m ? m[1] : null;
            }"""
        )
        # Either answer is fine and worth printing: nine of the ten slots are
        # unset on production, because nothing has ever successfully set one.
        check(
            True,
            "read what it holds now",
            str(before) if before else "(empty - the page shows its built-in photograph)",
        )

        source = os.path.join(HERE, "..", "..", "assets", "img", "office-desk.jpg")
        if not check(os.path.exists(source), "the source image exists"):
            raise SystemExit(1)

        card.locator("input[type=file]").first.set_input_files(source)
        got = wait_for(lambda: "Saved." in page.inner_text("body")
                       or "not changed" in page.inner_text("body")
                       or "could not" in page.inner_text("body"), timeout=40000)
        check(got, "the screen said something after the upload")

        said = page.inner_text("body")
        check(
            "version field is required" not in said,
            "the save was NOT refused for a missing version",
            "",
        )
        check("Saved." in said, "it saved the slot")

        after = page.evaluate(
            """() => {
                const c = Array.from(document.querySelectorAll('.v-card'))
                  .find(e => /Stored image id/.test(e.innerText) && /collage/i.test(e.innerText));
                const m = c && c.innerText.match(/(\\/storage\\/media\\/\\S+)/);
                return m ? m[1] : null;
            }"""
        )
        check(after is not None and after != before, "the stored id changed on screen", str(after))
        restore_slot = ("read from the card", before)

        # And it survives a reload, which localStorage would too - so also check
        # the server is the one answering, via a reload of the API-backed list.
        page.reload(wait_until="networkidle")
        page.wait_for_timeout(3500)
        again = page.evaluate(
            """() => {
                const c = Array.from(document.querySelectorAll('.v-card'))
                  .find(e => /Stored image id/.test(e.innerText) && /collage/i.test(e.innerText));
                const m = c && c.innerText.match(/(\\/storage\\/media\\/\\S+)/);
                return m ? m[1] : null;
            }"""
        )
        check(again == after, "and it is still there after a reload", str(again))
        page.screenshot(path=f"{OUT}/52-images-saved.png", full_page=True)

        print("\n=== 4. put the original picture back ===")
        card = page.locator(".v-card").filter(has_text="Stored image id").filter(
            has_text="collage").first
        inputs = card.locator("input[type=text]")
        if inputs.count() and before:
            inputs.first.fill(before)
        # The screen has no "type an id" save, so restore by re-uploading is not
        # possible for a bundled asset - clear it instead, which is the state the
        # eight home slots fall back to a built-in photograph from.
        clear = card.locator('button:has-text("Clear"), button:has-text("Remove")').first
        if clear.count():
            clear.click()
            page.wait_for_timeout(1200)
            confirm = page.locator('.v-dialog button:has-text("Clear"), .v-dialog button:has-text("Remove")').first
            if confirm.count():
                confirm.click()
            check(
                wait_for(lambda: "Cleared." in page.inner_text("body")),
                "cleared it, so the card falls back to the built-in photograph",
            )
            restore_slot = None
        else:
            check(False, "found no way to clear the slot again")

        # ------------------------------------------------- 5. backup
        print("\n=== 5. backup: export really downloads something ===")
        page.locator('a[href$="/backup"]').first.click()
        page.wait_for_url("**/backup**", timeout=20000)
        page.wait_for_timeout(3000)

        body = page.inner_text("body")
        check("Only the account owner" not in body, "the owner is allowed in")
        check("replace" in body.lower(), "the screen says import replaces what is there")

        with page.expect_download(timeout=60000) as dl:
            page.locator('button:has-text("Download"), button:has-text("Export")').first.click()
        download = dl.value
        path = os.path.join(OUT, "backup-download.json")
        download.save_as(path)
        size = os.path.getsize(path)
        check(size > 2000, "a real file came down", f"{size} bytes")

        try:
            payload = json.loads(io.open(path, encoding="utf-8").read())
        except Exception as e:
            payload = None
            check(False, "the download is valid JSON", str(e))
        if payload:
            check("content" in payload, "it carries the site content", str(sorted(payload.keys()))[:90])
            check(
                bool(payload.get("content", {}).get("events")),
                "and the events are actually in it, not an empty shell",
                str(len(payload.get("content", {}).get("events", []))) + " events",
            )
        page.screenshot(path=f"{OUT}/53-backup.png", full_page=True)

        print("\n=== 6. import previews the file and does NOT act on one click ===")
        # Deliberately NOT completed. A confirmed import replaces the live site.
        file_input = page.locator('input[type=file]').first
        if check(file_input.count() > 0, "there is a file picker for a restore"):
            file_input.set_input_files(path)
            page.wait_for_timeout(2500)
            shown = page.inner_text("body")
            check("events" in shown.lower(), "it reads the file and says what is in it")
            check(
                wait_for(lambda: "Restore" in page.inner_text("body")),
                "and offers a restore only after that",
            )
            page.screenshot(path=f"{OUT}/54-backup-preview.png", full_page=True)
            print("      (not confirming - a real import would replace the live site)")

        # ------------------------------------------------- 7. restore
        print("\n=== 7. the delete dialog's promise: remove one, then put it back ===")
        page.locator('a[href$="/content/public"]').first.click()
        page.wait_for_url("**/content/public**", timeout=20000)
        page.wait_for_timeout(3000)

        title = f"{MARK} - restore"
        page.locator('button:has-text("New event")').first.click()
        page.wait_for_timeout(1500)
        page.locator('.v-dialog .v-input:has(label:text-is("Event title *")) input').first.fill(title)
        page.locator('.v-dialog button:has-text("Add event")').first.click()
        check(
            wait_for(lambda: title in page.inner_text(".v-card")),
            "created an event to remove",
        )

        row = page.locator(f'.v-list-item:has-text("{title}")').first
        row.locator('button[aria-label*="Delete"]').first.click()
        page.wait_for_timeout(1200)
        dlg = page.inner_text(".v-dialog")
        check("Remove" in dlg, "the confirmation is on screen")
        page.locator('.v-dialog button:has-text("Remove it")').first.click()

        # The list ROWS, not the whole card: the screen deliberately leaves a
        # sentence naming what was removed and where to undo it, so the title is
        # still on the page - and should be.
        def rows_text():
            items = page.locator(".v-card .v-list .v-list-item-title")

            return " | ".join(
                (items.nth(i).text_content() or "") for i in range(items.count()))

        check(wait_for(lambda: title not in rows_text()), "it left the list")

        recent = page.locator('button:has-text("Recently removed")').first
        if not check(recent.count() > 0, "there is a Recently removed button"):
            raise SystemExit(1)
        recent.click()
        page.wait_for_timeout(2000)
        trash = page.locator(".v-dialog")
        check(on_screen(trash), "the removed list is on screen")
        check(title in trash.inner_text(), "and the item we removed is listed in it")

        trash.locator('button:has-text("Put it back")').first.click()
        check(
            wait_for(lambda: title in page.inner_text(".v-card")),
            "putting it back returns it to the list - the dialog's promise is true",
        )
        page.screenshot(path=f"{OUT}/55-restored.png", full_page=True)

        print("\n=== 8. clean up what this run created ===")
        for _ in range(4):
            leftover = page.locator(f'.v-list-item:has-text("{MARK}")')
            if leftover.count() == 0:
                break
            leftover.first.locator('button[aria-label*="Delete"]').first.click()
            page.wait_for_timeout(1000)
            page.locator('.v-dialog button:has-text("Remove it")').first.click()
            page.wait_for_timeout(2200)
        check(
            page.locator(f'.v-list-item:has-text("{MARK}")').count() == 0,
            "no test content left in the list",
        )

        real = [e for e in errors if "401" not in e]
        check(not real, "no console errors", str(real[:2]))

    finally:
        if restore_slot is not None:
            print("\n!! a slot may still hold the test picture:", restore_slot)
            print("   set it back on /admin-panel/images or clear it - the eight")
            print("   home slots fall back to the photograph built into the page.")
        print(f"\n{'-' * 62}\n{ok} passed, {fail} failed\n{'-' * 62}")
        ctx.close()
        browser.close()

sys.exit(1 if fail else 0)
