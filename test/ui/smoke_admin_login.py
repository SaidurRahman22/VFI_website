"""The journey the client actually takes: open the login page, type the
credentials, press the button, and see where you land.

Every earlier check signed in over the API and then navigated straight to
/admin-panel/ - which is exactly why it never noticed that the login itself
still sent people to the old panel. This one types into the form.
"""
import os
import sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("VFI_BASE", "http://103.14.23.151").rstrip("/")
OUT = os.path.dirname(os.path.abspath(__file__)) + "/panel-shots"
os.makedirs(OUT, exist_ok=True)

# From the environment. This repo is PUBLIC.
EMAIL = os.environ.get("VFI_ADMIN_EMAIL")
PASSWORD = os.environ.get("VFI_ADMIN_PASSWORD")
if not EMAIL or not PASSWORD:
    raise SystemExit("Set VFI_ADMIN_EMAIL and VFI_ADMIN_PASSWORD.")

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


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1500, "height": 950})
    page = ctx.new_page()
    errs = []
    page.on("console", lambda m: errs.append(m.text) if m.type == "error" else None)

    print("=== 1. open the login page, as a person would ===")
    page.goto(f"{BASE}/admin-login.html", wait_until="networkidle", timeout=60000)
    page.wait_for_timeout(1500)
    check("admin-login" in page.url, "landed on the login page", page.url)
    page.screenshot(path=f"{OUT}/10-login.png")

    print("\n=== 2. type the credentials and submit ===")
    # The form is progressive (password, then TOTP if required) - fill whatever
    # email/password inputs it actually renders rather than assuming ids.
    email = page.query_selector("input[type=email], input[name*=email i], #alEmail")
    pwd = page.query_selector("input[type=password]#alPass, input[type=password]")
    if not check(email is not None and pwd is not None, "the form has email + password inputs"):
        b.close()
        sys.exit(1)

    email.fill(EMAIL)
    pwd.fill(PASSWORD)
    page.query_selector("#stepPassword button, form button").click()

    print("\n=== 3. where did it take us? ===")
    try:
        page.wait_for_url("**/admin-panel/**", timeout=25000)
    except Exception:
        pass
    page.wait_for_timeout(3000)

    landed = page.url
    check("/admin-panel/" in landed, "login lands on the NEW console", landed)
    check("admin.html" not in landed, "and NOT on the legacy admin.html", landed)

    body = page.inner_text("body")
    check("VFI Admin" in body, "the new console rendered")
    check("saved in this browser" not in body, "no 'saved in this browser' claim anywhere")

    try:
        page.wait_for_selector("table tbody tr", timeout=20000)
    except Exception:
        pass

    print("\n=== 4. is anything still reachable that used to be? ===")
    nav = page.inner_text("aside, .layout-vertical-nav") if page.query_selector("aside, .layout-vertical-nav") else ""
    check("website content" in nav.lower(), "website content is still reachable from the console")
    check("not yet rebuilt here" in nav.lower(), "and it is labelled honestly, not passed off as native")

    page.screenshot(path=f"{OUT}/11-after-login.png", full_page=True)
    # The login page asks /api/admin/me "am I already signed in?" before you
    # sign in, which is a 401 by design. Only post-login errors matter.
    real = [e for e in errs if "401" not in e]
    check(not real, "no console errors after signing in", str(real[:2]))

    print(f"\n{'-' * 58}\n{ok} passed, {fail} failed\n{'-' * 58}")
    b.close()

sys.exit(1 if fail else 0)
